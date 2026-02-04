<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

require_once "../../config/db.php";
require_once "../../auth.php";

// Siempre antes de sacar HTML
require_login();

header('Content-Type: text/html; charset=utf-8');
mysqli_set_charset($conn, "utf8");

// ==========================
// SOLO ADMIN
// ==========================
if (($_SESSION["rol"] ?? "") !== "admin") {
    include "../../includes/header.php";
    echo "<div class='alert alert-danger m-3'>No tiene permisos para agregar caminos.</div>";
    include "../../includes/footer.php";
    exit;
}

// ==========================
// DATOS BÁSICOS PARA LOG
// ==========================
$usuarioLog = $_SESSION["usuario"] ?? "desconocido";
$rolLog     = $_SESSION["rol"] ?? "desconocido";
$ipLog      = $_SERVER["REMOTE_ADDR"] ?? "0.0.0.0";

// ==========================
// CARGAR DISTRITOS
// ==========================
$distritos = [];
$res = $conn->query("SELECT id, nombre FROM distritos WHERE activo = 1 ORDER BY nombre");
while ($row = $res->fetch_assoc()) {
    $distritos[] = $row;
}

// ==========================
// VARIABLES
// ==========================
$errores   = [];
$exito     = "";

$codigo         = "";
$nombre_camino  = "";   // <--- nombre del campo del formulario
$descripcion    = "";
$desde          = "";
$hasta          = "";
$distrito_id    = "";
$longitud       = "";
$estado         = "activo";   // valor por defecto
$tipologia      = "";         // A / B / C (o vacío)

// ==========================
// PROCESAR FORMULARIO
// ==========================
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $codigo        = trim($_POST["codigo"] ?? "");
    $nombre_camino = trim($_POST["nombre_camino"] ?? "");  // <--- leemos nombre_camino
    $descripcion   = trim($_POST["descripcion"] ?? "");
    $desde         = trim($_POST["desde"] ?? "");
    $hasta         = trim($_POST["hasta"] ?? "");
    $distrito_id   = trim($_POST["distrito_id"] ?? "");
    $longitud      = trim($_POST["longitud"] ?? "");
    $tipologia     = trim($_POST["tipologia"] ?? "");   // puede venir vacío

    // Validaciones básicas
    if ($codigo === "" || $nombre_camino === "") {
        $errores[] = "Código y Nombre son obligatorios.";
    }
    if ($distrito_id === "") {
        $errores[] = "Debe seleccionar un distrito.";
    }

    if (empty($errores)) {
        try {
            $sql = "INSERT INTO caminos
                    (codigo, nombre, descripcion, desde, hasta, distrito, longitud, estado, tipologia)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)";

            $stmt = $conn->prepare($sql);

            // Todos como string; MySQL convierte a número donde haga falta
            $stmt->bind_param(
                "sssssssss",
                $codigo,
                $nombre_camino,  // <--- esto se guarda en columna nombre
                $descripcion,
                $desde,
                $hasta,
                $distrito_id,    // id de distrito (string numérico)
                $longitud,       // decimal como string (ej: '0.074')
                $estado,         // 'activo'
                $tipologia       // 'A', 'B', 'C' o ''
            );

            $stmt->execute();
            $nuevoId = $conn->insert_id;
            $stmt->close();

            // ==========================
            // LOG: OBTENER REGISTRO NUEVO
            // ==========================
            $despues = [];
            $sel = $conn->prepare("SELECT * FROM caminos WHERE id = ?");
            if ($sel) {
                $sel->bind_param("i", $nuevoId);
                $sel->execute();
                $resCamino = $sel->get_result();
                if ($rowCamino = $resCamino->fetch_assoc()) {
                    $despues = $rowCamino;
                }
                $sel->close();
            }

            $detalle = [
                "tipo"      => "camino",
                "accion"    => "nuevo",
                "camino_id" => $nuevoId,
                "antes"     => null,
                "despues"   => $despues
            ];

            $jsonDetalle = json_encode($detalle, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

            $log = $conn->prepare("
                INSERT INTO logs_acciones (usuario, rol, accion, detalle, ip)
                VALUES (?, ?, 'CAMINO_NUEVO', ?, ?)
            ");
            if ($log) {
                $log->bind_param("ssss", $usuarioLog, $rolLog, $jsonDetalle, $ipLog);
                $log->execute();
                $log->close();
            }

            $exito = "✅ Camino agregado correctamente.";

            // Limpiar campos del formulario
            $codigo        = "";
            $nombre_camino = "";
            $descripcion   = "";
            $desde         = "";
            $hasta         = "";
            $longitud      = "";
            $distrito_id   = "";
            $tipologia     = "";

        } catch (Exception $e) {
            $errores[] = "Error al guardar: " . $e->getMessage();
        }
    }
}

// A partir de aquí ya podemos sacar HTML
include "../../includes/header.php";
?>

<div class="container py-4">
  <div class="d-flex justify-content-between align-items-center mb-3">
    <h3 class="fw-bold text-primary">
      <i class="bi bi-plus-circle"></i> Agregar Camino
    </h3>
    <a href="caminos.php" class="btn btn-secondary">
      <i class="bi bi-arrow-left"></i> Volver al listado
    </a>
  </div>

  <?php if (!empty($errores)): ?>
    <div class="alert alert-danger">
      <ul class="mb-0">
        <?php foreach ($errores as $e): ?>
          <li><?= htmlspecialchars($e, ENT_QUOTES, 'UTF-8') ?></li>
        <?php endforeach; ?>
      </ul>
    </div>
  <?php endif; ?>

  <?php if ($exito): ?>
    <div class="alert alert-success"><?= htmlspecialchars($exito, ENT_QUOTES, 'UTF-8') ?></div>
  <?php endif; ?>

  <div class="card shadow-sm">
    <div class="card-body">
      <form method="POST" class="row g-3" autocomplete="off">

        <div class="col-md-4">
          <label class="form-label">Código *</label>
          <input type="text" name="codigo" class="form-control"
                 value="<?= htmlspecialchars($codigo, ENT_QUOTES, 'UTF-8') ?>" required autocomplete="off">
        </div>

        <div class="col-md-8">
          <label class="form-label">Nombre *</label>
          <input type="text" name="nombre_camino" class="form-control"
                 value="<?= htmlspecialchars($nombre_camino, ENT_QUOTES, 'UTF-8') ?>" required autocomplete="off">
        </div>

        <div class="col-12">
          <label class="form-label">Descripción</label>
          <textarea name="descripcion" class="form-control" rows="2"><?= htmlspecialchars($descripcion, ENT_QUOTES, 'UTF-8') ?></textarea>
        </div>

        <div class="col-md-6">
          <label class="form-label">Desde</label>
          <input type="text" name="desde" class="form-control"
                 value="<?= htmlspecialchars($desde, ENT_QUOTES, 'UTF-8') ?>">
        </div>

        <div class="col-md-6">
          <label class="form-label">Hasta</label>
          <input type="text" name="hasta" class="form-control"
                 value="<?= htmlspecialchars($hasta, ENT_QUOTES, 'UTF-8') ?>">
        </div>

        <div class="col-md-4">
          <label class="form-label">Distrito</label>
          <select name="distrito_id" class="form-select" required>
            <option value="">Seleccione...</option>
            <?php foreach ($distritos as $d): ?>
              <option value="<?= $d['id'] ?>"
                <?= ($distrito_id !== "" && (int)$distrito_id === (int)$d['id']) ? 'selected' : '' ?>>
                <?= htmlspecialchars($d['nombre'], ENT_QUOTES, 'UTF-8') ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="col-md-4">
          <label class="form-label">Longitud (km)</label>
          <input type="number" step="0.001" name="longitud" class="form-control"
                 value="<?= htmlspecialchars($longitud, ENT_QUOTES, 'UTF-8') ?>">
        </div>

        <div class="col-md-4">
          <label class="form-label">Tipología (A/B/C)</label>
          <select name="tipologia" class="form-select">
            <option value="">Sin definir</option>
            <option value="A" <?= $tipologia === 'A' ? 'selected' : '' ?>>A</option>
            <option value="B" <?= $tipologia === 'B' ? 'selected' : '' ?>>B</option>
            <option value="C" <?= $tipologia === 'C' ? 'selected' : '' ?>>C</option>
          </select>
        </div>

        <div class="col-12 text-end">
          <button type="submit" class="btn btn-success">
            <i class="bi bi-save"></i> Guardar
          </button>
        </div>

      </form>
    </div>
  </div>
</div>

<?php include "../../includes/footer.php"; ?>
