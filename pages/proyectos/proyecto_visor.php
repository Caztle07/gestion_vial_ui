<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once "../../config/db.php";
require_once "../../auth.php";

// 1) Usuario debe estar logueado
require_login();

// 2) Solo roles con permiso para ver proyectos
if (!can_edit("proyectos") && !can_edit("proyectos_ver")) {
    header("Location: proyectos.php?err=" . urlencode("Sin permisos para ver proyectos."));
    exit;
}

// 3) Charset / headers (antes de sacar HTML)
header('Content-Type: text/html; charset=utf-8');
mysqli_set_charset($conn, "utf8");

// Helper de escape si no existe e()
if (!function_exists('e')) {
    function e($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
}

// ================================
// HELPERS DB (detectar tablas/columnas)
// ================================
function db_name(mysqli $conn): string {
    $r = $conn->query("SELECT DATABASE() AS db")->fetch_assoc();
    return (string)($r['db'] ?? '');
}
function table_exists(mysqli $conn, string $table): bool {
    $db = db_name($conn);
    if ($db === '') return false;
    $stmt = $conn->prepare("SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA=? AND TABLE_NAME=? LIMIT 1");
    $stmt->bind_param("ss", $db, $table);
    $stmt->execute();
    $ok = (bool)$stmt->get_result()->fetch_row();
    $stmt->close();
    return $ok;
}
function column_exists(mysqli $conn, string $table, string $column): bool {
    $db = db_name($conn);
    if ($db === '') return false;
    $stmt = $conn->prepare("
        SELECT 1
        FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA=? AND TABLE_NAME=? AND COLUMN_NAME=?
        LIMIT 1
    ");
    $stmt->bind_param("sss", $db, $table, $column);
    $stmt->execute();
    $ok = (bool)$stmt->get_result()->fetch_row();
    $stmt->close();
    return $ok;
}

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id <= 0) {
    include "../../includes/header.php";
    echo "<div class='alert alert-danger m-3'>ID de proyecto inválido</div>";
    include "../../includes/footer.php";
    exit;
}

// Mensajes opcionales
$mensajeOk  = $_GET['ok']  ?? '';
$mensajeErr = $_GET['err'] ?? '';

// Mapa de estados del proyecto
$mapEstadosProyecto = [
    '1' => 'Pendiente',
    '2' => 'En ejecución',
    '3' => 'Finalizado',
    '4' => 'Suspendido',
    '0' => 'En papelera'
];

// Flags
$tienePuenteCaminos    = table_exists($conn, "proyecto_caminos");
$tienePuenteDistritos  = table_exists($conn, "proyecto_distritos");
$tieneTareasCatalogo   = table_exists($conn, "tareas_catalogo");
$tieneTiposCronica     = table_exists($conn, "tipos_cronica");
$tieneTablaMontos      = table_exists($conn, "proyectos_montos");

// ✅ METAS (compatibilidad)
$tieneMetasCatalogo    = table_exists($conn, "metas_proyecto");
$tieneMetasPuente      = table_exists($conn, "proyecto_metas");
$tieneMetas            = $tieneMetasCatalogo && $tieneMetasPuente;

// Cierre de proyecto (compatibilidad: solo si existe columna)
$tieneCierre = column_exists($conn, "proyectos", "cerrado");

// Permiso para cerrar/reabrir: mismo que editar proyectos
$puedeCerrar = can_edit("proyectos");

// ================================
// CARGAR PROYECTO (base)
// ================================
$selectCierre = $tieneCierre ? ", p.cerrado, p.cerrado_por, p.cerrado_en" : ", 0 AS cerrado, NULL AS cerrado_por, NULL AS cerrado_en";

$stmtP = $conn->prepare("
    SELECT p.id,
           p.nombre AS titulo,
           p.descripcion,
           p.estado,
           p.avance,
           p.fecha_inicio,
           p.fecha_fin,
           p.monto_invertido,
           p.inventario_id,
           p.distrito_id,
           e.nombre AS encargado,
           m.nombre AS modalidad,
           d.nombre AS distrito
           $selectCierre
    FROM proyectos p
    LEFT JOIN encargados e  ON e.id = p.encargado_id
    LEFT JOIN modalidades m ON m.id = p.modalidad_id
    LEFT JOIN distritos d   ON d.id = p.distrito_id
    WHERE p.id = ? AND (p.activo IS NULL OR p.activo = 1)
    LIMIT 1
");
$stmtP->bind_param("i", $id);
$stmtP->execute();
$resP = $stmtP->get_result();
$proy = $resP ? $resP->fetch_assoc() : null;
$stmtP->close();

if (!$proy) {
    include "../../includes/header.php";
    echo "<div class='alert alert-warning m-3'>Proyecto no encontrado o está desactivado.</div>";
    include "../../includes/footer.php";
    exit;
}

// Traducir estado
$estadoValor  = (string)($proy['estado'] ?? '');
$estadoNombre = $mapEstadosProyecto[$estadoValor] ?? ($estadoValor !== '' ? $estadoValor : 'Sin estado');

// Estado cierre
$cerrado = (int)($proy['cerrado'] ?? 0) === 1;

// ================================
// CAMINOS (MULTI si existe puente)
// ================================
$caminosTxt = '-';

if ($tienePuenteCaminos) {
    $stmtC = $conn->prepare("
        SELECT c.codigo, IFNULL(c.nombre,'') AS nombre
        FROM proyecto_caminos pc
        INNER JOIN caminos c ON c.id = pc.camino_id
        WHERE pc.proyecto_id = ?
        ORDER BY c.codigo
    ");
    $stmtC->bind_param("i", $id);
    $stmtC->execute();
    $resC = $stmtC->get_result();
    $arr = [];
    while ($resC && ($r = $resC->fetch_assoc())) {
        $arr[] = trim(($r['codigo'] ?? '') . ' - ' . ($r['nombre'] ?? ''));
    }
    $stmtC->close();

    if (!empty($arr)) {
        $caminosTxt = implode(" | ", $arr);
    } else {
        $inv = (int)($proy['inventario_id'] ?? 0);
        if ($inv > 0) {
            $stmtInv = $conn->prepare("SELECT codigo, IFNULL(nombre,'') AS nombre FROM caminos WHERE id=? LIMIT 1");
            $stmtInv->bind_param("i", $inv);
            $stmtInv->execute();
            $rInv = $stmtInv->get_result()->fetch_assoc();
            $stmtInv->close();
            if ($rInv) $caminosTxt = trim(($rInv['codigo'] ?? '') . ' - ' . ($rInv['nombre'] ?? ''));
        }
    }
} else {
    $inv = (int)($proy['inventario_id'] ?? 0);
    if ($inv > 0) {
        $stmtInv = $conn->prepare("SELECT codigo, IFNULL(nombre,'') AS nombre FROM caminos WHERE id=? LIMIT 1");
        $stmtInv->bind_param("i", $inv);
        $stmtInv->execute();
        $rInv = $stmtInv->get_result()->fetch_assoc();
        $stmtInv->close();
        if ($rInv) $caminosTxt = trim(($rInv['codigo'] ?? '') . ' - ' . ($rInv['nombre'] ?? ''));
    }
}

// ================================
// DISTRITOS (MULTI si existe proyecto_distritos)
// ================================
$distritosTxt = '-';

if ($tienePuenteDistritos) {
    $stmtD = $conn->prepare("
        SELECT d.nombre
        FROM proyecto_distritos pd
        INNER JOIN distritos d ON d.id = pd.distrito_id
        WHERE pd.proyecto_id = ?
        ORDER BY d.nombre
    ");
    $stmtD->bind_param("i", $id);
    $stmtD->execute();
    $resD = $stmtD->get_result();

    $arrD = [];
    while ($resD && ($r = $resD->fetch_assoc())) {
        $arrD[] = (string)($r['nombre'] ?? '');
    }
    $stmtD->close();

    $arrD = array_values(array_filter(array_map('trim', $arrD), fn($x) => $x !== ''));
    if (!empty($arrD)) {
        $distritosTxt = implode(" | ", $arrD);
    } else {
        $distritosTxt = trim((string)($proy['distrito'] ?? '')) !== '' ? (string)$proy['distrito'] : '-';
    }
} else {
    $distritosTxt = trim((string)($proy['distrito'] ?? '')) !== '' ? (string)$proy['distrito'] : '-';
}

// ================================
// TIPOS DE PROYECTO
// ================================
$tiposNombres = [];

if ($tieneTareasCatalogo) {
    $stmtTipos = $conn->prepare("
        SELECT tc.nombre
        FROM proyecto_tipos pt
        INNER JOIN tareas_catalogo tc ON tc.id = pt.tipo_id
        WHERE pt.proyecto_id = ?
        ORDER BY tc.nombre
    ");
    $stmtTipos->bind_param("i", $id);
    $stmtTipos->execute();
    $resTipos = $stmtTipos->get_result();
    while ($resTipos && ($rowT = $resTipos->fetch_assoc())) {
        $tiposNombres[] = $rowT['nombre'];
    }
    $stmtTipos->close();
} elseif ($tieneTiposCronica) {
    $stmtTipos = $conn->prepare("
        SELECT tc.nombre
        FROM proyecto_tipos pt
        INNER JOIN tipos_cronica tc ON tc.id = pt.tipo_id
        WHERE pt.proyecto_id = ?
        ORDER BY tc.nombre
    ");
    $stmtTipos->bind_param("i", $id);
    $stmtTipos->execute();
    $resTipos = $stmtTipos->get_result();
    while ($resTipos && ($rowT = $resTipos->fetch_assoc())) {
        $tiposNombres[] = $rowT['nombre'];
    }
    $stmtTipos->close();
}

$textoTipos = !empty($tiposNombres) ? implode(", ", $tiposNombres) : 'Sin tipos asociados';

// ================================
// ✅ METAS DEL PROYECTO (solo lectura)
// ================================
$metasNombres = [];
if ($tieneMetas) {
    $stmtMetas = $conn->prepare("
        SELECT mp.nombre
        FROM proyecto_metas pm
        INNER JOIN metas_proyecto mp ON mp.id = pm.meta_id
        WHERE pm.proyecto_id = ? AND (mp.activo IS NULL OR mp.activo = 1)
        ORDER BY mp.nombre
    ");
    $stmtMetas->bind_param("i", $id);
    $stmtMetas->execute();
    $resMetas = $stmtMetas->get_result();
    while ($resMetas && ($rowM = $resMetas->fetch_assoc())) {
        $metasNombres[] = (string)$rowM['nombre'];
    }
    $stmtMetas->close();
}
$textoMetas = !empty($metasNombres) ? implode(", ", $metasNombres) : 'Sin metas asociadas';

// ================================
// HISTORIAL DE MONTOS (solo lectura)
// ================================
$historial = [];
if ($tieneTablaMontos) {
    $stmtHist = $conn->prepare("
        SELECT fecha, monto, nota, creado_en
        FROM proyectos_montos
        WHERE proyecto_id = ?
        ORDER BY fecha DESC, id DESC
    ");
    $stmtHist->bind_param("i", $id);
    $stmtHist->execute();
    $resHist = $stmtHist->get_result();
    while ($resHist && ($rowH = $resHist->fetch_assoc())) {
        $historial[] = $rowH;
    }
    $stmtHist->close();
}

// A partir de aquí ya podemos sacar HTML
include "../../includes/header.php";
?>
<div class="container py-4" style="max-width:900px;">

    <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
        <div>
            <h4 class="fw-bold text-primary mb-0">Proyecto #<?= (int)$proy['id'] ?></h4>

            <div class="mt-1 d-flex flex-wrap gap-2 align-items-center">
                <?php if ($tieneCierre): ?>
                    <span class="badge <?= $cerrado ? 'bg-danger' : 'bg-success' ?>">
                        <?= $cerrado ? 'Cerrado' : 'Abierto' ?>
                    </span>
                <?php endif; ?>

                <span class="badge bg-secondary"><?= e($estadoNombre) ?></span>
            </div>
        </div>

        <div class="d-flex gap-2 flex-wrap">
            <?php if (($_SESSION["rol"] ?? "") === "admin"): ?>
                <a class="btn btn-outline-dark btn-sm"
                   target="_blank"
                   href="proyecto_pdf.php?id=<?= (int)$proy['id'] ?>">
                    <i class="bi bi-filetype-pdf"></i> Exportar PDF
                </a>
            <?php endif; ?>

            <?php if ($tieneCierre && $puedeCerrar): ?>
                <?php if (!$cerrado): ?>
                    <button class="btn btn-outline-danger btn-sm"
                            data-bs-toggle="modal"
                            data-bs-target="#modalCerrarProyecto">
                        <i class="bi bi-lock-fill"></i> Cerrar proyecto
                    </button>
                <?php else: ?>
                    <button class="btn btn-outline-success btn-sm"
                            data-bs-toggle="modal"
                            data-bs-target="#modalReabrirProyecto">
                        <i class="bi bi-unlock-fill"></i> Reabrir proyecto
                    </button>
                <?php endif; ?>
            <?php endif; ?>

            <a href="proyectos.php" class="btn btn-secondary btn-sm">
                <i class="bi bi-arrow-left"></i> Volver
            </a>
        </div>
    </div>

    <?php if ($mensajeOk): ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            <?= e($mensajeOk) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <?php if ($mensajeErr): ?>
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <?= e($mensajeErr) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <!-- CARD PRINCIPAL DEL PROYECTO -->
    <div class="card shadow-sm mb-4">
        <div class="card-header bg-primary text-white fw-semibold">
            <?= e($proy['titulo'] ?? '') ?>
        </div>
        <div class="card-body">

            <?php if ($tieneCierre && $cerrado): ?>
                <div class="alert alert-warning">
                    Este proyecto está <strong>cerrado</strong>. No se deben registrar nuevas crónicas hasta reabrirlo.
                </div>
            <?php endif; ?>

            <div class="row g-3">
                <div class="col-md-6">
                    <div class="small text-muted">Camino(s)</div>
                    <div><?= e($caminosTxt) ?></div>
                </div>

                <div class="col-md-6">
                    <div class="small text-muted">Encargado</div>
                    <div><?= e($proy['encargado'] ?? '-') ?></div>
                </div>

                <div class="col-md-6">
                    <div class="small text-muted">Modalidad</div>
                    <div><?= e($proy['modalidad'] ?? '-') ?></div>
                </div>

                <div class="col-md-6">
                    <div class="small text-muted">Distrito(s)</div>
                    <div><?= e($distritosTxt) ?></div>
                </div>

                <div class="col-12">
                    <div class="small text-muted">Tipos de proyecto</div>
                    <div><?= e($textoTipos) ?></div>
                </div>

                <!-- ✅ METAS (solo si existe feature) -->
                <?php if ($tieneMetas): ?>
                <div class="col-12">
                    <div class="small text-muted">Metas del proyecto</div>
                    <div><?= e($textoMetas) ?></div>
                </div>
                <?php endif; ?>

                <div class="col-md-3">
                    <div class="small text-muted">Fecha inicio</div>
                    <div><?= e($proy['fecha_inicio'] ?? '-') ?></div>
                </div>

                <div class="col-md-3">
                    <div class="small text-muted">Fecha fin</div>
                    <div><?= e($proy['fecha_fin'] ?? '-') ?></div>
                </div>

                <div class="col-md-3">
                    <div class="small text-muted">Estado</div>
                    <div><?= e($estadoNombre) ?></div>
                </div>

                <div class="col-md-3">
                    <div class="small text-muted">Avance</div>
                    <div><?= (int)($proy['avance'] ?? 0) ?>%</div>
                </div>

                <div class="col-12">
                    <div class="small text-muted">Monto invertido total</div>
                    <div class="text-success">
                        <?php
                            $montoTotal = isset($proy['monto_invertido']) ? (float)$proy['monto_invertido'] : 0;
                            echo '₡ ' . number_format($montoTotal, 2, ',', '.');
                        ?>
                    </div>
                </div>
            </div>

            <hr>

            <div class="fw-semibold mb-2">Descripción del proyecto</div>
            <div class="border rounded p-3 bg-light">
                <?php
                    $descRaw = $proy['descripcion'] ?? '';
                    $descSegura = strip_tags(
                        (string)$descRaw,
                        '<p><br><b><strong><i><em><u><ul><ol><li><table><thead><tbody><tr><th><td>'
                    );

                    echo trim($descSegura) !== ''
                        ? $descSegura
                        : '<span class="text-muted">Sin descripción registrada.</span>';
                ?>
            </div>

        </div>
    </div>

    <!-- CARD: HISTORIAL DE MONTOS (solo lectura) -->
    <div class="card shadow-sm mb-4">
        <div class="card-header bg-light fw-semibold">
            Historial de montos registrados
        </div>
        <div class="card-body">
            <?php if (empty($historial)): ?>
                <div class="text-muted">
                    No hay montos registrados para este proyecto.
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-sm table-striped align-middle mb-0">
                        <thead class="table-secondary">
                            <tr>
                                <th style="width:15%;">Fecha</th>
                                <th style="width:20%;">Monto (₡)</th>
                                <th>Nota</th>
                                <th style="width:20%;">Registrado en</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($historial as $h): ?>
                            <tr>
                                <td><?= e($h['fecha'] ?? '') ?></td>
                                <td>
                                    <?php
                                        $m = isset($h['monto']) ? (float)$h['monto'] : 0;
                                        echo '₡ ' . number_format($m, 2, ',', '.');
                                    ?>
                                </td>
                                <td><?= e($h['nota'] ?? '') ?></td>
                                <td><?= e($h['creado_en'] ?? '') ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>

</div>

<?php if ($tieneCierre && $puedeCerrar && !$cerrado): ?>
<!-- MODAL CERRAR -->
<div class="modal fade" id="modalCerrarProyecto" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header bg-danger text-white">
        <h5 class="modal-title">Cerrar proyecto</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>

      <form method="POST" action="proyecto_toggle_cierre.php">
        <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="id" value="<?= (int)$id ?>">
        <input type="hidden" name="accion" value="cerrar">

        <div class="modal-body">
          <div class="alert alert-warning">
            Al cerrar el proyecto, se bloquea el registro de nuevas crónicas (online y offline al sincronizar).
          </div>

          <div class="form-check">
            <input class="form-check-input" type="checkbox" id="confirmCerrar" required>
            <label class="form-check-label" for="confirmCerrar">
              Confirmo que deseo cerrar este proyecto.
            </label>
          </div>
        </div>

        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
          <button type="submit" class="btn btn-danger">Cerrar</button>
        </div>
      </form>
    </div>
  </div>
</div>
<?php endif; ?>

<?php if ($tieneCierre && $puedeCerrar && $cerrado): ?>
<!-- MODAL REABRIR -->
<div class="modal fade" id="modalReabrirProyecto" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header bg-success text-white">
        <h5 class="modal-title">Reabrir proyecto</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>

      <form method="POST" action="proyecto_toggle_cierre.php">
        <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="id" value="<?= (int)$id ?>">
        <input type="hidden" name="accion" value="reabrir">

        <div class="modal-body">
          <div class="alert alert-info">
            Al reabrir el proyecto, se permite nuevamente registrar crónicas.
          </div>

          <div class="form-check">
            <input class="form-check-input" type="checkbox" id="confirmReabrir" required>
            <label class="form-check-label" for="confirmReabrir">
              Confirmo que deseo reabrir este proyecto.
            </label>
          </div>
        </div>

        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
          <button type="submit" class="btn btn-success">Reabrir</button>
        </div>
      </form>
    </div>
  </div>
</div>
<?php endif; ?>

<?php include "../../includes/footer.php"; ?>
