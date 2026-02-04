<?php
require_once "../../auth.php";
require_once "../../config/db.php";

// siempre primero
require_login();

include "../../includes/header.php";

mysqli_set_charset($conn, "utf8");
error_reporting(E_ALL);
ini_set('display_errors', 1);

function e($s): string {
  return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

// Solo admin (ajusta si tu can_edit funciona distinto)
$rol = strtolower(trim($_SESSION["rol"] ?? "vista"));
$puedeEditar = ($rol === "admin") || (function_exists('can_edit') && can_edit("admin"));

// Roles válidos SEGÚN TU BD
$ROLES_VALIDOS = ["admin","ingeniero","inspector","vista"];

$ok  = "";
$err = "";

// ===========================
// Acciones (crear/editar/eliminar)
// ===========================
if ($_SERVER["REQUEST_METHOD"] === "POST" && $puedeEditar) {

  $accion = $_POST["accion"] ?? "";

  // ---------- NUEVO ----------
  if ($accion === "nuevo") {

    $usuario  = trim((string)($_POST["usuario"] ?? ""));
    $nombre   = trim((string)($_POST["nombre"] ?? ""));
    $rolNuevo = trim((string)($_POST["rol"] ?? ""));
    $passRaw  = (string)($_POST["password"] ?? "");

    if ($usuario === "" || $nombre === "" || $passRaw === "") {
      $err = "Complete usuario, nombre y contraseña.";
    } elseif (!in_array($rolNuevo, $ROLES_VALIDOS, true)) {
      $err = "Rol inválido. Use: admin, ingeniero, inspector o vista.";
    } else {
      // Verificar usuario único
      $stmtChk = $conn->prepare("SELECT id FROM usuarios WHERE usuario=? LIMIT 1");
      $stmtChk->bind_param("s", $usuario);
      $stmtChk->execute();
      $existe = $stmtChk->get_result()->fetch_assoc();
      $stmtChk->close();

      if ($existe) {
        $err = "Ya existe un usuario con ese nombre de usuario.";
      } else {
        // bcrypt (RECOMENDADO)
        $hash = password_hash($passRaw, PASSWORD_BCRYPT);

        $stmt = $conn->prepare("INSERT INTO usuarios (usuario, password, rol, nombre) VALUES (?, ?, ?, ?)");
        if (!$stmt) {
          $err = "Error preparando INSERT: " . $conn->error;
        } else {
          $stmt->bind_param("ssss", $usuario, $hash, $rolNuevo, $nombre);
          if ($stmt->execute()) {
            $ok = "Usuario creado correctamente.";
          } else {
            $err = "No se pudo crear el usuario: " . $stmt->error;
          }
          $stmt->close();
        }
      }
    }
  }

  // ---------- EDITAR ----------
  if ($accion === "editar") {

    $id      = (int)($_POST["id"] ?? 0);
    $usuario = trim((string)($_POST["usuario"] ?? ""));
    $nombre  = trim((string)($_POST["nombre"] ?? ""));
    $rolEd   = trim((string)($_POST["rol"] ?? ""));
    $passRaw = (string)($_POST["password"] ?? ""); // opcional

    if ($id <= 0) {
      $err = "ID inválido.";
    } elseif ($usuario === "" || $nombre === "") {
      $err = "Usuario y nombre no pueden ir vacíos.";
    } elseif (!in_array($rolEd, $ROLES_VALIDOS, true)) {
      $err = "Rol inválido. Use: admin, ingeniero, inspector o vista.";
    } else {
      // Validar que "usuario" no choque con otro registro
      $stmtChk = $conn->prepare("SELECT id FROM usuarios WHERE usuario=? AND id<>? LIMIT 1");
      $stmtChk->bind_param("si", $usuario, $id);
      $stmtChk->execute();
      $dup = $stmtChk->get_result()->fetch_assoc();
      $stmtChk->close();

      if ($dup) {
        $err = "Ese nombre de usuario ya lo está usando otra persona.";
      } else {
        if ($passRaw !== "") {
          $hash = password_hash($passRaw, PASSWORD_BCRYPT);
          $stmt = $conn->prepare("UPDATE usuarios SET usuario=?, nombre=?, rol=?, password=? WHERE id=?");
          if (!$stmt) {
            $err = "Error preparando UPDATE: " . $conn->error;
          } else {
            $stmt->bind_param("ssssi", $usuario, $nombre, $rolEd, $hash, $id);
            if ($stmt->execute()) $ok = "Usuario actualizado correctamente.";
            else $err = "No se pudo actualizar: " . $stmt->error;
            $stmt->close();
          }
        } else {
          $stmt = $conn->prepare("UPDATE usuarios SET usuario=?, nombre=?, rol=? WHERE id=?");
          if (!$stmt) {
            $err = "Error preparando UPDATE: " . $conn->error;
          } else {
            $stmt->bind_param("sssi", $usuario, $nombre, $rolEd, $id);
            if ($stmt->execute()) $ok = "Usuario actualizado correctamente.";
            else $err = "No se pudo actualizar: " . $stmt->error;
            $stmt->close();
          }
        }
      }
    }
  }

  // ---------- ELIMINAR ----------
  if ($accion === "eliminar") {

    $id = (int)($_POST["id"] ?? 0);

    if ($id <= 0) {
      $err = "ID inválido.";
    } else {
      // Evitar que te borres a vos mismo por error
      $miId = (int)($_SESSION["id"] ?? 0);
      if ($miId === $id) {
        $err = "No puede eliminar su propio usuario.";
      } else {
        $stmt = $conn->prepare("DELETE FROM usuarios WHERE id=?");
        if (!$stmt) {
          $err = "Error preparando DELETE: " . $conn->error;
        } else {
          $stmt->bind_param("i", $id);
          if ($stmt->execute()) $ok = "Usuario eliminado.";
          else $err = "No se pudo eliminar: " . $stmt->error;
          $stmt->close();
        }
      }
    }
  }
}

// listar
$usuarios = $conn->query("SELECT * FROM usuarios ORDER BY id ASC");
?>

<div class="container-fluid py-4">
  <h2 class="fw-semibold mb-3"><i class="bi bi-person-gear"></i> Gestión de Usuarios</h2>

  <?php if ($ok !== ""): ?>
    <div class="alert alert-success shadow-sm"><?= e($ok) ?></div>
  <?php endif; ?>

  <?php if ($err !== ""): ?>
    <div class="alert alert-danger shadow-sm"><?= e($err) ?></div>
  <?php endif; ?>

  <?php if ($puedeEditar): ?>
  <form method="POST" class="row g-2 mb-4 card card-body shadow-sm">
    <input type="hidden" name="accion" value="nuevo">

    <div class="col-md-3">
      <label class="form-label fw-semibold">Usuario</label>
      <input name="usuario" class="form-control" placeholder="Usuario" required>
    </div>

    <div class="col-md-3">
      <label class="form-label fw-semibold">Nombre completo</label>
      <input name="nombre" class="form-control" placeholder="Nombre completo" required>
    </div>

    <div class="col-md-3">
      <label class="form-label fw-semibold">Contraseña</label>
      <input name="password" type="password" class="form-control" placeholder="Contraseña" required>
    </div>

    <div class="col-md-2">
      <label class="form-label fw-semibold">Rol</label>
      <select name="rol" class="form-select" required>
        <option value="admin">Administrador</option>
        <option value="ingeniero">Ingeniero</option>
        <option value="inspector">Inspector</option>
        <option value="vista">Vista</option>
      </select>
      <div class="form-text">Nota: “encargado” no existe en tu BD.</div>
    </div>

    <div class="col-md-12 d-flex justify-content-end mt-3">
      <button class="btn btn-success px-4">
        <i class="bi bi-person-plus"></i> Agregar Usuario
      </button>
    </div>
  </form>
  <?php endif; ?>

  <div class="card shadow-sm">
    <div class="table-responsive">
      <table class="table table-striped mb-0 align-middle">
        <thead class="table-light">
          <tr>
            <th>ID</th>
            <th>Usuario</th>
            <th>Nombre</th>
            <th>Rol</th>
            <th class="text-end">Acciones</th>
          </tr>
        </thead>

        <tbody>
          <?php if ($usuarios && $usuarios->num_rows > 0): ?>
            <?php while ($u = $usuarios->fetch_assoc()): ?>
              <?php
                $rolU = (string)($u["rol"] ?? "");
                $badge =
                  $rolU === "admin" ? "danger" :
                  ($rolU === "ingeniero" ? "success" :
                  ($rolU === "inspector" ? "primary" : "secondary"));
              ?>
              <tr>
                <td><?= (int)$u["id"] ?></td>
                <td><?= e($u["usuario"]) ?></td>
                <td><?= e($u["nombre"]) ?></td>
                <td><span class="badge bg-<?= e($badge) ?>"><?= e(ucfirst($rolU)) ?></span></td>

                <td class="text-end">
                  <?php if ($puedeEditar): ?>
                    <button class="btn btn-outline-warning btn-sm" data-bs-toggle="modal" data-bs-target="#edit<?= (int)$u['id'] ?>">
                      <i class="bi bi-pencil"></i>
                    </button>

                    <form method="POST" class="d-inline" onsubmit="return confirm('¿Eliminar este usuario?')">
                      <input type="hidden" name="accion" value="eliminar">
                      <input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
                      <button class="btn btn-outline-danger btn-sm">
                        <i class="bi bi-trash"></i>
                      </button>
                    </form>
                  <?php else: ?>
                    <span class="text-muted">—</span>
                  <?php endif; ?>
                </td>
              </tr>

              <!-- Modal Editar -->
              <div class="modal fade" id="edit<?= (int)$u['id'] ?>" tabindex="-1" aria-hidden="true">
                <div class="modal-dialog modal-dialog-centered">
                  <div class="modal-content">
                    <div class="modal-header bg-primary text-white">
                      <h5 class="modal-title"><i class="bi bi-pencil-square"></i> Editar Usuario</h5>
                      <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                    </div>

                    <form method="POST">
                      <input type="hidden" name="accion" value="editar">
                      <input type="hidden" name="id" value="<?= (int)$u['id'] ?>">

                      <div class="modal-body">
                        <div class="row g-2">
                          <div class="col-md-6">
                            <label class="form-label">Usuario</label>
                            <input name="usuario" value="<?= e($u['usuario']) ?>" class="form-control" required>
                          </div>

                          <div class="col-md-6">
                            <label class="form-label">Nombre</label>
                            <input name="nombre" value="<?= e($u['nombre']) ?>" class="form-control" required>
                          </div>

                          <div class="col-md-6">
                            <label class="form-label">Rol</label>
                            <select name="rol" class="form-select" required>
                              <option value="admin"     <?= ($u['rol']=="admin"?"selected":"") ?>>Administrador</option>
                              <option value="ingeniero" <?= ($u['rol']=="ingeniero"?"selected":"") ?>>Ingeniero</option>
                              <option value="inspector" <?= ($u['rol']=="inspector"?"selected":"") ?>>Inspector</option>
                              <option value="vista"     <?= ($u['rol']=="vista"?"selected":"") ?>>Vista</option>
                            </select>
                          </div>

                          <div class="col-md-6">
                            <label class="form-label">Nueva Contraseña (opcional)</label>
                            <input name="password" type="password" class="form-control" placeholder="Dejar en blanco si no cambia">
                            <div class="form-text">Se guarda con bcrypt.</div>
                          </div>
                        </div>
                      </div>

                      <div class="modal-footer d-flex justify-content-between">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                          <i class="bi bi-x-circle"></i> Cancelar
                        </button>
                        <button class="btn btn-success">
                          <i class="bi bi-save"></i> Guardar Cambios
                        </button>
                      </div>
                    </form>

                  </div>
                </div>
              </div>

            <?php endwhile; ?>
          <?php else: ?>
            <tr><td colspan="5" class="text-muted p-4">No hay usuarios.</td></tr>
          <?php endif; ?>
        </tbody>

      </table>
    </div>
  </div>
</div>

<?php include "../../includes/footer.php"; ?>
