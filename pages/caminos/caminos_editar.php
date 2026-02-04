<?php
require_once "../../config/db.php";
require_once "../../auth.php";
include "../../includes/header.php";

header('Content-Type: text/html; charset=utf-8');
mysqli_set_charset($conn, "utf8");

// Solo admin
if (($_SESSION["rol"] ?? "") !== "admin") {
    echo "<div class='alert alert-danger m-3'>No tiene permisos para editar caminos.</div>";
    include "../../includes/footer.php";
    exit;
}

$id = intval($_GET["id"] ?? 0);
if ($id <= 0) {
    echo "<div class='alert alert-danger m-3'>ID inválido.</div>";
    include "../../includes_footer.php";
    exit;
}

// Cargar registro
$stmt = $conn->prepare("SELECT * FROM caminos WHERE id = ?");
$stmt->bind_param("i", $id);
$stmt->execute();
$res  = $stmt->get_result();
$item = $res->fetch_assoc();
$stmt->close();

if (!$item) {
    echo "<div class='alert alert-danger m-3'>Camino no encontrado.</div>";
    include "../../includes/footer.php";
    exit;
}

// Datos base para logs
$usuarioLog = $_SESSION["usuario"] ?? "desconocido";
$rolLog     = $_SESSION["rol"] ?? "desconocido";
$ipLog = gethostbyaddr($_SERVER["REMOTE_ADDR"] ?? "0.0.0.0");

$errores = [];
$exito   = "";

// Si post, actualizar
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $codigo      = trim($_POST["codigo"] ?? "");
    $nombre      = trim($_POST["nombre"] ?? "");
    $descripcion = trim($_POST["descripcion"] ?? "");
    $desde       = trim($_POST["desde"] ?? "");
    $hasta       = trim($_POST["hasta"] ?? "");
    $distrito    = trim($_POST["distrito"] ?? "");
    $longitud    = trim($_POST["longitud"] ?? "");
    $estado      = trim($_POST["estado"] ?? "");
    $tipologia   = trim($_POST["tipologia"] ?? "");

    // SOLO código es obligatorio
    if ($codigo === "") {
        $errores[] = "El Código es obligatorio.";
    }

    if (empty($errores)) {

        // Guardar estado ANTES para el log
        $antes = $item;

        $stmtUp = $conn->prepare("
          UPDATE caminos
          SET codigo=?, nombre=?, descripcion=?, desde=?, hasta=?, 
              distrito=?, longitud=?, estado=?, tipologia=?
          WHERE id=?
        ");

        $stmtUp->bind_param(
            "sssssssssi",
            $codigo,
            $nombre,
            $descripcion,
            $desde,
            $hasta,
            $distrito,
            $longitud,
            $estado,
            $tipologia,
            $id
        );

        if ($stmtUp->execute()) {
            $stmtUp->close();

            // LOG DESPUÉS
            $despues = [];
            $stmt2 = $conn->prepare("SELECT * FROM caminos WHERE id = ?");
            $stmt2->bind_param("i", $id);
            $stmt2->execute();
            $r2 = $stmt2->get_result();
            if ($row2 = $r2->fetch_assoc()) {
                $despues = $row2;
            }
            $stmt2->close();

            $detalle = [
                "tipo"      => "camino",
                "accion"    => "editar",
                "camino_id" => $id,
                "antes"     => $antes,
                "despues"   => $despues
            ];

            $jsonDetalle = json_encode($detalle, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

            $log = $conn->prepare("
                INSERT INTO logs_acciones (usuario, rol, accion, detalle, ip)
                VALUES (?, ?, 'CAMINO_EDITAR', ?, ?)
            ");
            $log->bind_param("ssss", $usuarioLog, $rolLog, $jsonDetalle, $ipLog);
            $log->execute();
            $log->close();

            // REDIRECCIÓN INMEDIATA AL INVENTARIO ⬅⬅⬅⬅
            header("Location: caminos.php");
            exit;

        } else {
            $errores[] = "Error al actualizar: " . $conn->error;
            $stmtUp->close();
        }
    }
}
?>

<div class="container py-4">
  <div class="d-flex justify-content-between align-items-center mb-3">
    <h3 class="fw-bold text-primary">
      <i class="bi bi-pencil"></i> Editar Camino #<?= $item["id"] ?>
    </h3>
    <a href="caminos.php" class="btn btn-secondary">
      <i class="bi bi-arrow-left"></i> Volver al listado
    </a>
  </div>

  <?php if (!empty($errores)): ?>
    <div class="alert alert-danger">
      <ul class="mb-0">
        <?php foreach($errores as $e): ?>
          <li><?= htmlspecialchars($e) ?></li>
        <?php endforeach; ?>
      </ul>
    </div>
  <?php endif; ?>

  <div class="card shadow-sm">
    <div class="card-body">
      <form method="POST" class="row g-3" autocomplete="off">

        <div class="col-md-4">
          <label class="form-label">Código *</label>
          <input type="text" name="codigo" class="form-control"
                 value="<?= htmlspecialchars($item["codigo"]) ?>" required>
        </div>

        <div class="col-md-8">
          <label class="form-label">Nombre</label>
          <input type="text" name="nombre" class="form-control"
                 value="<?= htmlspecialchars($item["nombre"]) ?>">
        </div>

        <div class="col-12">
          <label class="form-label">Descripción</label>
          <textarea name="descripcion" class="form-control" rows="2"><?= htmlspecialchars($item["descripcion"]) ?></textarea>
        </div>

        <div class="col-md-6">
          <label class="form-label">Desde</label>
          <input type="text" name="desde" class="form-control"
                 value="<?= htmlspecialchars($item["desde"]) ?>">
        </div>

        <div class="col-md-6">
          <label class="form-label">Hasta</label>
          <input type="text" name="hasta" class="form-control"
                 value="<?= htmlspecialchars($item["hasta"]) ?>">
        </div>

        <div class="col-md-4">
          <label class="form-label">Distrito</label>
          <input type="text" name="distrito" class="form-control"
                 value="<?= htmlspecialchars($item["distrito"]) ?>">
        </div>

        <div class="col-md-4">
          <label class="form-label">Longitud (km)</label>
          <input type="number" step="0.001" name="longitud" class="form-control"
                 value="<?= htmlspecialchars($item["longitud"]) ?>">
        </div>

        <div class="col-md-4">
          <label class="form-label">Estado</label>
          <input type="text" name="estado" class="form-control"
                 value="<?= htmlspecialchars($item["estado"]) ?>">
        </div>

        <div class="col-md-4">
          <label class="form-label">Tipología (A/B/C)</label>
          <select name="tipologia" class="form-select">
            <option value="">Sin definir</option>
            <option value="A" <?= ($item["tipologia"] ?? '') === 'A' ? 'selected' : '' ?>>A</option>
            <option value="B" <?= ($item["tipologia"] ?? '') === 'B' ? 'selected' : '' ?>>B</option>
            <option value="C" <?= ($item["tipologia"] ?? '') === 'C' ? 'selected' : '' ?>>C</option>
          </select>
        </div>

        <div class="col-12 text-end">
          <button type="submit" class="btn btn-success">
            <i class="bi bi-save"></i> Guardar cambios
          </button>
        </div>

      </form>
    </div>
  </div>
</div>

<?php include "../../includes/footer.php"; ?>
