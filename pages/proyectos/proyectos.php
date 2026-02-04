<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once "../../config/db.php";
require_once "../../auth.php";

require_login();

if (!can_edit("proyectos") && !can_edit("proyectos_ver")) {
    echo "<div class='alert alert-danger m-3'>❌ No tiene permiso para ver proyectos.</div>";
    exit;
}

header('Content-Type: text/html; charset=utf-8');
mysqli_set_charset($conn, "utf8");

include "../../includes/header.php";

$mensaje_ok  = isset($_GET['ok'])  ? trim($_GET['ok'])  : '';
$mensaje_err = isset($_GET['err']) ? trim($_GET['err']) : '';

if (!function_exists('e')) {
    function e($s): string {
        return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
    }
}

// ================================
// HELPERS DB
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

// ================================
// NORMALIZAR ESTADO (NUM/TEXTO)
// ================================
function map_estado($raw): array {
    $s = trim((string)$raw);

    if (ctype_digit($s)) {
        $n = (int)$s;
        return match($n) {
            1 => ['txt'=>'Pendiente',    'color'=>'info'],
            2 => ['txt'=>'En ejecución', 'color'=>'warning'],
            3 => ['txt'=>'Finalizado',   'color'=>'success'],
            4 => ['txt'=>'Suspendido',   'color'=>'secondary'],
            default => ['txt'=>'Desconocido', 'color'=>'secondary'],
        };
    }

    $l = mb_strtolower($s);
    if ($l === '' || $l === 'iniciada' || $l === 'inicio' || $l === 'nuevo' || $l === 'nueva') {
        return ['txt'=>'Pendiente', 'color'=>'info'];
    }
    if (str_contains($l, 'ejec')) {
        return ['txt'=>'En ejecución', 'color'=>'warning'];
    }
    if (str_contains($l, 'final')) {
        return ['txt'=>'Finalizado', 'color'=>'success'];
    }
    if (str_contains($l, 'susp')) {
        return ['txt'=>'Suspendido', 'color'=>'secondary'];
    }

    return ['txt'=>($s !== '' ? $s : 'Desconocido'), 'color'=>'secondary'];
}

// ================================
// FLAGS TABLAS PUENTE
// ================================
$tienePuenteCaminos   = table_exists($conn, "proyecto_caminos");
$tienePuenteDistritos = table_exists($conn, "proyecto_distritos");

// ✅ Metas (N:N)
$tieneMetasCatalogo = table_exists($conn, "metas_proyecto");
$tieneMetasPuente   = table_exists($conn, "proyecto_metas");
$tieneMetas         = $tieneMetasCatalogo && $tieneMetasPuente;

// ================================
// BUSQUEDA
// ================================
$buscar = $_GET['q'] ?? '';
$where  = "p.activo = 1";
$params = [];
$types  = "";

// ================================
// SELECT/JOIN CAMINOS
// ================================
if ($tienePuenteCaminos) {
    $selectCaminos = "COALESCE(
                        GROUP_CONCAT(
                            DISTINCT CONCAT(i2.codigo,' - ',IFNULL(i2.nombre,''))
                            ORDER BY i2.codigo SEPARATOR ' | '
                        ),
                        '-'
                      ) AS caminos_txt,";
    $joinCaminos = "
        LEFT JOIN proyecto_caminos pc ON pc.proyecto_id = p.id
        LEFT JOIN caminos i2 ON i2.id = pc.camino_id
    ";
} else {
    $selectCaminos = "COALESCE(CONCAT(i.codigo,' - ',IFNULL(i.nombre,'')),'-') AS caminos_txt,";
    $joinCaminos   = "";
}

// ================================
// SELECT/JOIN DISTRITOS
// ================================
if ($tienePuenteDistritos) {
    $selectDistritos = "COALESCE(
                          GROUP_CONCAT(DISTINCT d2.nombre ORDER BY d2.nombre SEPARATOR ' | '),
                          '-'
                        ) AS distritos_txt,";
    $joinDistritos = "
        LEFT JOIN proyecto_distritos pd ON pd.proyecto_id = p.id
        LEFT JOIN distritos d2 ON d2.id = pd.distrito_id
    ";
} else {
    $selectDistritos = "COALESCE(d.nombre,'-') AS distritos_txt,";
    $joinDistritos   = "";
}

// ================================
// ✅ SELECT/JOIN METAS
// ================================
if ($tieneMetas) {
    $selectMetas = "COALESCE(
        GROUP_CONCAT(DISTINCT mp2.nombre ORDER BY mp2.nombre SEPARATOR ' | '),
        '-'
    ) AS metas_txt,";
    $joinMetas = "
        LEFT JOIN proyecto_metas pm2 ON pm2.proyecto_id = p.id
        LEFT JOIN metas_proyecto mp2 ON mp2.id = pm2.meta_id AND (mp2.activo = 1 OR mp2.activo IS NULL)
    ";
} else {
    $selectMetas = "'-' AS metas_txt,";
    $joinMetas   = "";
}

// ================================
// WHERE BUSQUEDA
// ================================
if ($buscar !== '') {
    $where .= " AND (
        p.nombre LIKE ? OR
        e.nombre LIKE ? OR
        m.nombre LIKE ? OR
        tc.nombre LIKE ? OR
        " . ($tienePuenteCaminos ? "i2.nombre LIKE ? OR i2.codigo LIKE ? OR " : "i.nombre LIKE ? OR i.codigo LIKE ? OR ") . "
        " . ($tienePuenteDistritos ? "d2.nombre LIKE ? OR " : "d.nombre LIKE ? OR ") . "
        " . ($tieneMetas ? "mp2.nombre LIKE ?" : "1=0") . "
    )";

    $like = "%$buscar%";

    // 4 fijos + 3 de caminos/distritos + 1 metas
    if ($tieneMetas) {
        $params = [$like, $like, $like, $like, $like, $like, $like, $like];
        $types  = "ssssssss";
    } else {
        // sin metas
        $params = [$like, $like, $like, $like, $like, $like, $like];
        $types  = "sssssss";
    }
}

// ================================
// LISTADO PROYECTOS
// ================================
$sql = "
    SELECT
        p.id,
        p.nombre,
        p.estado,
        p.fecha_inicio,
        p.avance,
        p.monto_invertido,
        e.nombre AS encargado,
        m.nombre AS modalidad,
        YEAR(p.fecha_inicio) AS anio,
        $selectCaminos
        $selectDistritos
        $selectMetas
        COALESCE(GROUP_CONCAT(DISTINCT tc.nombre ORDER BY tc.nombre SEPARATOR ', '), '-') AS tipos_proyecto
    FROM proyectos p
    " . ($tienePuenteCaminos ? "" : "LEFT JOIN caminos i ON i.id = p.inventario_id") . "
    " . ($tienePuenteDistritos ? "" : "LEFT JOIN distritos d ON d.id = p.distrito_id") . "
    $joinCaminos
    $joinDistritos
    $joinMetas
    LEFT JOIN encargados      e  ON e.id = p.encargado_id
    LEFT JOIN modalidades     m  ON m.id = p.modalidad_id
    LEFT JOIN proyecto_tipos  pt ON pt.proyecto_id = p.id
    LEFT JOIN tareas_catalogo tc ON tc.id = pt.tipo_id
    WHERE $where
    GROUP BY
        p.id, p.nombre, p.estado, p.fecha_inicio, p.avance, p.monto_invertido,
        e.nombre, m.nombre
    ORDER BY p.id DESC
";

$stmt = $conn->prepare($sql);
if ($types) $stmt->bind_param($types, ...$params);
$stmt->execute();
$lista = $stmt->get_result();

// ================================
// LISTAS PARA MODAL
// ================================
$caminosRes = $conn->query("SELECT id, codigo, nombre FROM caminos ORDER BY codigo, nombre");
$caminosArr = [];
while ($row = $caminosRes->fetch_assoc()) { $caminosArr[] = $row; }

$encRes = $conn->query("SELECT id, nombre FROM encargados WHERE activo = 1 ORDER BY nombre");
$encargadosArr = [];
while ($row = $encRes->fetch_assoc()) { $encargadosArr[] = $row; }

$disRes = $conn->query("SELECT id, nombre FROM distritos ORDER BY nombre");
$distritosArr = [];
while ($row = $disRes->fetch_assoc()) { $distritosArr[] = $row; }

$tipRes = $conn->query("SELECT id, nombre FROM tareas_catalogo ORDER BY nombre");
$tiposProyectoArr = [];
while ($row = $tipRes->fetch_assoc()) { $tiposProyectoArr[] = $row; }

// ✅ metas catálogo (solo si existe)
$metasArr = [];
if ($tieneMetasCatalogo) {
    $metasRes = $conn->query("SELECT id, nombre, descripcion FROM metas_proyecto WHERE activo=1 ORDER BY nombre");
    while ($row = $metasRes->fetch_assoc()) { $metasArr[] = $row; }
}

// Modalidades permitidas
$modalidadesPermitidas = [
    "Rehabilitación",
    "Mantenimiento periódico",
    "Mantenimiento rutinario",
    "Mejoramiento"
];

$modalidadesRows = [];
if (!empty($modalidadesPermitidas)) {
    $placeholders = implode(",", array_fill(0, count($modalidadesPermitidas), "?"));
    $sqlMods = "SELECT id, nombre FROM modalidades WHERE nombre IN ($placeholders) ORDER BY nombre ASC";
    $stmtMods = $conn->prepare($sqlMods);
    $typesMods = str_repeat("s", count($modalidadesPermitidas));
    $stmtMods->bind_param($typesMods, ...$modalidadesPermitidas);
    $stmtMods->execute();
    $resMods = $stmtMods->get_result();
    while ($row = $resMods->fetch_assoc()) $modalidadesRows[] = $row;
    $stmtMods->close();
}

$faltantes = [];
foreach ($modalidadesPermitidas as $n) {
    $existe = false;
    foreach ($modalidadesRows as $r) {
        if (trim($r['nombre']) === $n) { $existe = true; break; }
    }
    if (!$existe) $faltantes[] = $n;
}
?>

<div class="container py-4">

  <?php if ($mensaje_ok !== ''): ?>
    <div class="alert alert-success alert-dismissible fade show shadow-sm" role="alert">
      <i class="bi bi-check-circle-fill me-2"></i>
      <?= e($mensaje_ok) ?>
      <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Cerrar"></button>
    </div>
  <?php endif; ?>

  <?php if ($mensaje_err !== ''): ?>
    <div class="alert alert-danger alert-dismissible fade show shadow-sm" role="alert">
      <i class="bi bi-exclamation-triangle-fill me-2"></i>
      <?= e($mensaje_err) ?>
      <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Cerrar"></button>
    </div>
  <?php endif; ?>

  <?php if (!empty($faltantes)): ?>
    <div class="alert alert-warning shadow-sm">
      <strong>Atención:</strong> Estas modalidades no existen en la tabla <code>modalidades</code>:
      <ul class="mb-0">
        <?php foreach ($faltantes as $f): ?><li><?= e($f) ?></li><?php endforeach; ?>
      </ul>
    </div>
  <?php endif; ?>

  <?php if (!$tieneMetas): ?>
    <div class="alert alert-info shadow-sm">
      <strong>Metas:</strong> No están activas en BD (faltan tablas <code>metas_proyecto</code> o <code>proyecto_metas</code>).
      El sistema seguirá funcionando sin metas.
    </div>
  <?php endif; ?>

  <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-4">
    <h3 class="fw-bold text-primary mb-0">
      <i class="bi bi-diagram-3"></i> Gestión de Proyectos
    </h3>

    <div class="d-flex flex-wrap gap-2">
      <a class="btn btn-outline-primary fw-bold shadow-sm" href="proyectos_calendario.php">
        <i class="bi bi-calendar3"></i> Calendario
      </a>

      <?php if (($_SESSION["rol"] ?? "") === "admin"): ?>
        <a class="btn btn-outline-dark fw-bold shadow-sm"
           target="_blank"
           href="proyectos_pdf.php?q=<?= urlencode($buscar) ?>">
          <i class="bi bi-filetype-pdf"></i> Exportar PDF
        </a>
      <?php endif; ?>

      <button type="button"
              class="btn btn-success fw-bold shadow-sm"
              data-bs-toggle="modal"
              data-bs-target="#modalNuevoProyecto">
        <i class="bi bi-plus-circle"></i> Nuevo
      </button>
    </div>
  </div>

  <form method="GET"
        class="d-flex flex-wrap align-items-center justify-content-between mb-4 p-3 shadow-sm rounded-3 bg-light border">
    <div class="input-group flex-grow-1 me-2" style="max-width: 600px;">
      <span class="input-group-text bg-primary text-white">
        <i class="bi bi-search"></i>
      </span>
      <input type="text"
             name="q"
             value="<?= e($buscar) ?>"
             class="form-control"
             placeholder="Buscar por nombre, camino, distrito, encargado, modalidad, tipo o meta...">
    </div>

    <div class="d-flex gap-2">
      <button class="btn btn-primary fw-bold shadow-sm">
        <i class="bi bi-search"></i> Buscar
      </button>
      <a href="proyectos.php" class="btn btn-outline-secondary fw-bold shadow-sm">
        <i class="bi bi-x-circle"></i> Limpiar
      </a>
    </div>
  </form>

  <div class="card shadow border-0 rounded-3">
    <div class="card-header bg-primary text-white fw-semibold py-3 rounded-top-3">
      <i class="bi bi-list-task"></i> Lista de Proyectos Activos
    </div>

    <div class="table-responsive">
      <table class="table table-striped table-hover align-middle text-center mb-0">
        <thead class="table-primary">
          <tr>
            <th style="width:5%;">ID</th>
            <th style="width:20%;">Camino(s)</th>
            <th style="width:12%;">Encargado</th>
            <th style="width:12%;">Modalidad</th>
            <th style="width:12%;">Tipos de proyecto</th>
            <th style="width:12%;">Metas</th>
            <th style="width:12%;">Distrito(s)</th>
            <th style="width:6%;">Año</th>
            <th style="width:10%;">Estado</th>
            <th style="width:6%;">Avance</th>
            <th style="width:13%;">Monto invertido</th>
            <th style="width:10%;">Acciones</th>
          </tr>
        </thead>

        <tbody>
        <?php if (!$lista || $lista->num_rows == 0): ?>
          <tr>
            <td colspan="12" class="text-center text-muted py-4">
              No hay proyectos activos registrados.
            </td>
          </tr>
        <?php else: ?>
          <?php while ($p = $lista->fetch_assoc()): ?>
            <?php $infoEstado = map_estado($p['estado'] ?? ''); ?>
            <tr>
              <td class="fw-semibold"><?= (int)$p['id'] ?></td>

              <td class="text-start"><?= e($p['caminos_txt'] ?? '-') ?></td>
              <td><?= e($p['encargado'] ?? '-') ?></td>
              <td><?= e($p['modalidad'] ?? '-') ?></td>
              <td><?= e($p['tipos_proyecto'] ?? '-') ?></td>
              <td class="text-start"><?= e($p['metas_txt'] ?? '-') ?></td>
              <td class="text-start"><?= e($p['distritos_txt'] ?? '-') ?></td>
              <td><?= e($p['anio'] ?? '-') ?></td>

              <td>
                <span class="badge bg-<?= e($infoEstado['color']) ?> px-3 py-2">
                  <?= e($infoEstado['txt']) ?>
                </span>
              </td>

              <td><?= (int)($p['avance'] ?? 0) ?>%</td>

              <td class="text-end">
                <?php
                  $monto = isset($p['monto_invertido']) ? (float)$p['monto_invertido'] : 0;
                  echo '₡ ' . number_format($monto, 2, ',', '.');
                ?>
              </td>

              <td>
                <div class="btn-group">
                  <a href="proyecto_visor.php?id=<?= (int)$p['id'] ?>" class="btn btn-outline-info btn-sm" title="Ver detalles">
                    <i class="bi bi-eye"></i>
                  </a>
                  <a href="proyecto_editar.php?id=<?= (int)$p['id'] ?>" class="btn btn-outline-warning btn-sm" title="Editar">
                    <i class="bi bi-pencil-square"></i>
                  </a>
                  <a href="proyecto_eliminar.php?id=<?= (int)$p['id'] ?>"
                     class="btn btn-outline-danger btn-sm"
                     onclick="return confirm('¿Mover este proyecto a la papelera?')"
                     title="Mover a papelera">
                    <i class="bi bi-trash"></i>
                  </a>
                </div>
              </td>
            </tr>
          <?php endwhile; ?>
        <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<!-- MODAL NUEVO PROYECTO -->
<div class="modal fade" id="modalNuevoProyecto" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-xl modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header bg-primary text-white">
        <h5 class="modal-title"><i class="bi bi-plus-circle"></i> Nuevo Proyecto</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>

      <form id="formNuevoProyecto"
            method="POST"
            action="proyectos_guardar.php"
            enctype="multipart/form-data"
            class="needs-validation"
            novalidate>

        <input type="hidden" name="_csrf" value="<?= function_exists('csrf_token') ? e(csrf_token()) : '' ?>">

        <div class="modal-body">
          <div class="row g-3">

            <div class="col-12">
              <label class="form-label fw-bold">Nombre del proyecto <span class="text-danger">*</span></label>
              <input name="nombre" class="form-control" required>
            </div>

            <div class="col-12">
              <label class="form-label fw-bold">Descripción detallada <span class="text-danger">*</span></label>
              <textarea name="descripcion" id="descripcion_nuevo" class="form-control" required></textarea>
            </div>

            <div class="col-md-6">
              <label class="form-label fw-bold">Camino(s) (Inventario) <span class="text-danger">*</span></label>
              <select name="caminos[]" class="form-select select2" multiple required>
                <?php foreach ($caminosArr as $c): ?>
                  <option value="<?= (int)$c['id'] ?>"><?= e(trim($c['codigo'] . ' - ' . $c['nombre'])) ?></option>
                <?php endforeach; ?>
              </select>
              <div class="form-text">Puede seleccionar 1 o varios caminos.</div>
            </div>

            <div class="col-md-6">
              <label class="form-label fw-bold">Modalidad <span class="text-danger">*</span></label>
              <select name="modalidad_id" class="form-select select2" required>
                <option value="">Seleccione...</option>
                <?php foreach ($modalidadesRows as $m): ?>
                  <option value="<?= (int)$m['id'] ?>"><?= e($m['nombre']) ?></option>
                <?php endforeach; ?>
              </select>
              <div class="form-text">Solo se permiten: Rehabilitación, Mantenimiento periódico, Mantenimiento rutinario, Mejoramiento.</div>
            </div>

            <div class="col-md-6">
              <label class="form-label fw-bold">Distrito(s) <span class="text-danger">*</span></label>
              <select name="distritos[]" class="form-select select2" multiple required>
                <?php foreach ($distritosArr as $d): ?>
                  <option value="<?= (int)$d['id'] ?>"><?= e($d['nombre']) ?></option>
                <?php endforeach; ?>
              </select>
              <div class="form-text">Puede seleccionar 1 o varios distritos.</div>
            </div>

            <div class="col-md-6">
              <label class="form-label fw-bold">Encargado <span class="text-danger">*</span></label>
              <select name="encargado_id" class="form-select select2" required>
                <option value="">Seleccione...</option>
                <?php foreach ($encargadosArr as $e2): ?>
                  <option value="<?= (int)$e2['id'] ?>"><?= e($e2['nombre']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>

            <div class="col-12">
              <label class="form-label fw-bold">Tipos de proyecto</label>
              <select name="tipo_id[]" class="form-select select2" multiple>
                <?php foreach ($tiposProyectoArr as $t): ?>
                  <option value="<?= (int)$t['id'] ?>"><?= e($t['nombre']) ?></option>
                <?php endforeach; ?>
              </select>
              <div class="form-text">Puede seleccionar uno o varios tipos (desde catálogo de tareas).</div>
            </div>

            <!-- ✅ METAS (NUEVO) -->
            <?php if ($tieneMetas): ?>
              <div class="col-12">
                <label class="form-label fw-bold">Metas del proyecto <span class="text-danger">*</span></label>
                <select name="metas[]" class="form-select select2" multiple required>
                  <?php foreach ($metasArr as $mt): ?>
                    <option value="<?= (int)$mt['id'] ?>" title="<?= e($mt['descripcion'] ?? '') ?>">
                      <?= e($mt['nombre']) ?>
                    </option>
                  <?php endforeach; ?>
                </select>
                <div class="form-text">
                  Seleccioná 1 o varias metas (Administración, Contratado, Primer impacto CNE, etc.).
                </div>
                <div class="invalid-feedback">Seleccione al menos una meta.</div>
              </div>
            <?php else: ?>
              <div class="col-12">
                <div class="alert alert-secondary mb-0">
                  <strong>Metas:</strong> aún no disponibles (faltan tablas). Se puede crear el proyecto sin metas por ahora.
                </div>
              </div>
            <?php endif; ?>

            <div class="col-md-4">
              <label class="form-label fw-bold">Estado</label>
              <select name="estado" class="form-select">
                <option value="1" selected>Pendiente</option>
                <option value="2">En ejecución</option>
                <option value="3">Finalizado</option>
                <option value="4">Suspendido</option>
              </select>
            </div>

            <div class="col-md-4">
              <label class="form-label fw-bold">Fecha inicio <span class="text-danger">*</span></label>
              <input type="date" name="fecha_inicio" class="form-control" required>
            </div>

            <div class="col-md-4">
              <label class="form-label fw-bold">Fecha fin <span class="text-danger">*</span></label>
              <input type="date" name="fecha_fin" class="form-control" required>
            </div>

            <div class="col-12">
              <label class="form-label fw-bold">Avance</label>
              <div class="d-flex align-items-center gap-2">
                <input type="range" name="avance" min="0" max="100" value="0" class="form-range flex-grow-1"
                       oninput="this.nextElementSibling.value=this.value+'%'">
                <output>0%</output>
              </div>
            </div>

            <div class="col-md-6">
              <label class="form-label fw-bold">Monto inicial (₡)</label>
              <input type="number" name="monto_inicial" class="form-control" step="0.01" min="0" placeholder="Opcional">
              <div class="form-text">Este monto se agregará al historial automáticamente.</div>
            </div>

            <div class="col-12">
              <label class="form-label fw-bold">Documentos generales del proyecto (PDF, Word, Excel)</label>
              <input type="file" name="docs_generales[]" multiple accept=".pdf,.doc,.docx,.xls,.xlsx" class="form-control">
              <div class="form-text">Se guardarán en <code>docs_generales</code> del proyecto.</div>
            </div>

          </div>
        </div>

        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
          <button type="submit" class="btn btn-success fw-bold"><i class="bi bi-save"></i> Guardar</button>
        </div>
      </form>
    </div>
  </div>
</div>

<link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
<script src="https://cdn.jsdelivr.net/npm/jquery@3.7.1/dist/jquery.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
<script src="https://cdn.ckeditor.com/ckeditor5/41.2.1/classic/ckeditor.js"></script>

<script>
let editorProyecto = null;

document.addEventListener('DOMContentLoaded', function () {
  const modal = document.getElementById('modalNuevoProyecto');
  if (!modal || typeof ClassicEditor === 'undefined') return;

  $('.select2').select2({ dropdownParent: $('#modalNuevoProyecto'), width: '100%' });

  modal.addEventListener('shown.bs.modal', function () {
    const textarea = document.querySelector('#descripcion_nuevo');
    if (!textarea) return;

    if (editorProyecto) { editorProyecto.destroy().catch(() => {}); editorProyecto = null; }

    ClassicEditor.create(textarea, {
      toolbar: ['heading','|','bold','italic','underline','|','bulletedList','numberedList','|','blockQuote','|','link','|','insertTable','|','undo','redo'],
      table: { contentToolbar: ['tableColumn','tableRow','mergeTableCells'] }
    }).then(editor => { editorProyecto = editor; })
      .catch(error => console.error(error));
  });

  modal.addEventListener('hidden.bs.modal', function () {
    if (editorProyecto) { editorProyecto.destroy().catch(() => {}); editorProyecto = null; }
  });

  const form = document.getElementById('formNuevoProyecto');
  if (form) {
    form.addEventListener('submit', function(e) {
      if (editorProyecto) form.descripcion.value = editorProyecto.getData().trim();
      if (!form.checkValidity()) { e.preventDefault(); e.stopPropagation(); }
      form.classList.add('was-validated');
    });
  }
});
</script>

<style>
.select2-container--default .select2-selection--multiple {
  min-height: 40px !important;
  max-height: 80px !important;
  overflow-y: auto !important;
  overflow-x: hidden !important;
  padding-bottom: 4px !important;
}
.select2-container--default .select2-selection--multiple .select2-selection__choice {
  padding: 2px 6px !important;
  font-size: 0.8rem !important;
  margin-top: 2px !important;
}
.select2-container--default .select2-selection__rendered {
  max-height: 70px !important;
  overflow-y: auto !important;
}
</style>

<?php include "../../includes/footer.php"; ?>

<script>
document.addEventListener('DOMContentLoaded', function () {
  const alerts = document.querySelectorAll('.alert-dismissible');
  if (!alerts.length) return;
  setTimeout(() => {
    alerts.forEach(a => {
      const alert = bootstrap.Alert.getOrCreateInstance(a);
      alert.close();
    });
  }, 5000);
});
</script>
