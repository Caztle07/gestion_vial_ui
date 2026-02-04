<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once "../../config/db.php";
require_once "../../auth.php";

require_login();

header('Content-Type: text/html; charset=utf-8');
mysqli_set_charset($conn, "utf8");

// SOLO ROL VISTA
$rol = $_SESSION["rol"] ?? "Invitado";
if ($rol !== "vista") {
    header("Location: ../../index.php?err=" . urlencode("Sin permisos. Solo rol VISTA."));
    exit;
}

// =======================
// HELPERS
// =======================
function e($s): string {
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

function slugify($s): string {
    $s = (string)$s;
    $s = trim($s);
    if ($s === '') return 'proyecto';
    $s = preg_replace('/[^A-Za-z0-9_\-]/', '_', $s);
    $s = preg_replace('/_+/', '_', $s);
    return trim($s, '_');
}

/*
  IMPORTANTE:
  Tus crónicas ahora guardan en /data/proyectos/{ID}/...
  entonces el "slug" real para resolver rutas debe ser el ID del proyecto.
  Se deja fallback al slug por nombre para data vieja.
*/
function getProjectFolderId(array $proyecto): string {
    $id = (int)($proyecto['id'] ?? 0);
    return (string)$id;
}

function getProjectSlugLegacy(array $proyecto): string {
    $nombre = $proyecto['nombre'] ?? '';
    $id     = (int)($proyecto['id'] ?? 0);
    if (trim((string)$nombre) === '') return "proyecto_" . $id;
    return slugify($nombre);
}

function getRootPath(): string {
    $rootGuess = __DIR__ . DIRECTORY_SEPARATOR . ".." . DIRECTORY_SEPARATOR . ".."; // .../gestion_vial_ui
    $root = realpath($rootGuess);
    return $root ? $root : $rootGuess;
}

function normalizarImagenes($imagenesRaw): array {
    $out = [];
    if (!is_array($imagenesRaw)) return $out;

    foreach ($imagenesRaw as $item) {
        if (is_array($item) && isset($item['file'])) {
            $file = trim((string)$item['file']);
            if ($file === '') continue;
            $desc = isset($item['desc']) ? trim((string)$item['desc']) : '';
            $out[] = ['file' => $file, 'desc' => $desc];
            continue;
        }

        if (is_string($item)) {
            $file = trim($item);
            if ($file === '') continue;
            $out[] = ['file' => $file, 'desc' => ''];
            continue;
        }
    }
    return $out;
}

/*
  Resuelve URL de imagen con prioridad:
  1) /data/proyectos/{projectId}/cronicas_img/{file}
  2) fallback legacy: /data/proyectos/{projectSlugLegacy}/cronicas_img/{file}
  3) global: /data/cronicas_img/{file}
*/
function resolverUrlImagen(string $projectIdFolder, string $projectSlugLegacy, string $file): array {
    $root = getRootPath();
    $file = trim($file);

    $fsById = $root . DIRECTORY_SEPARATOR . "data" . DIRECTORY_SEPARATOR . "proyectos" . DIRECTORY_SEPARATOR . $projectIdFolder . DIRECTORY_SEPARATOR . "cronicas_img" . DIRECTORY_SEPARATOR . $file;
    $fsLegacy = $root . DIRECTORY_SEPARATOR . "data" . DIRECTORY_SEPARATOR . "proyectos" . DIRECTORY_SEPARATOR . $projectSlugLegacy . DIRECTORY_SEPARATOR . "cronicas_img" . DIRECTORY_SEPARATOR . $file;
    $fsGlobal = $root . DIRECTORY_SEPARATOR . "data" . DIRECTORY_SEPARATOR . "cronicas_img" . DIRECTORY_SEPARATOR . $file;

    $urlById = "/gestion_vial_ui/data/proyectos/" . rawurlencode($projectIdFolder) . "/cronicas_img/" . rawurlencode($file);
    $urlLegacy = "/gestion_vial_ui/data/proyectos/" . rawurlencode($projectSlugLegacy) . "/cronicas_img/" . rawurlencode($file);
    $urlGlobal = "/gestion_vial_ui/data/cronicas_img/" . rawurlencode($file);

    if (is_file($fsById))   return ['url' => $urlById,   'scope' => 'proyecto_id'];
    if (is_file($fsLegacy)) return ['url' => $urlLegacy, 'scope' => 'legacy_slug'];
    if (is_file($fsGlobal)) return ['url' => $urlGlobal, 'scope' => 'global'];

    // si no existe, devolvemos la de ID (por defecto)
    return ['url' => $urlById, 'scope' => 'proyecto_id'];
}

function tiposTexto(mysqli $conn, $tipoJson): string {
    $arr = json_decode((string)$tipoJson, true);
    if (!is_array($arr) || empty($arr)) return "—";

    $ids = [];
    foreach ($arr as $v) {
        $iv = (int)$v;
        if ($iv > 0) $ids[] = $iv;
    }
    $ids = array_values(array_unique($ids));
    if (empty($ids)) return "—";

    $in = implode(",", $ids);
    $rs = $conn->query("SELECT nombre FROM tipos_cronica WHERE id IN ($in)");
    $nombres = [];
    if ($rs) {
        while ($r = $rs->fetch_assoc()) {
            $n = trim((string)$r['nombre']);
            if ($n !== '') $nombres[] = $n;
        }
    }
    return !empty($nombres) ? implode(", ", $nombres) : "—";
}

function resumenSeguro($comentariosHtml, $observaciones): string {
    $texto = trim((string)$comentariosHtml);
    if ($texto !== '') {
        $texto = strip_tags($texto);
        $texto = preg_replace("/\s+/", " ", $texto);
    } else {
        $texto = trim((string)$observaciones);
        $texto = preg_replace("/\s+/", " ", $texto);
    }

    if ($texto === '') return "Sin detalle registrado.";
    if (mb_strlen($texto) > 130) return mb_substr($texto, 0, 130) . "...";
    return $texto;
}

// =======================
// LISTADO DE PROYECTOS (activos)
// =======================
$sqlProyectos = "
    SELECT p.id, p.nombre, p.estado,
           d.nombre AS distrito_nombre,
           e.nombre AS encargado_nombre
    FROM proyectos p
    LEFT JOIN distritos d ON d.id = p.distrito_id
    LEFT JOIN encargados e ON e.id = p.encargado_id
    WHERE p.estado != '0'
    ORDER BY p.id DESC
";
$proyectos = $conn->query($sqlProyectos);

// Proyecto seleccionado
$proyecto_id   = isset($_GET["proyecto_id"]) ? (int)$_GET["proyecto_id"] : 0;
$proyecto_sel  = null;
$cronicas      = [];
$projectIdFolder = "";
$projectSlugLegacy = "";

if ($proyecto_id > 0) {
    $stmt = $conn->prepare("
        SELECT p.*,
               d.nombre AS distrito_nombre,
               e.nombre AS encargado_nombre
        FROM proyectos p
        LEFT JOIN distritos d ON d.id = p.distrito_id
        LEFT JOIN encargados e ON e.id = p.encargado_id
        WHERE p.id = ?
        LIMIT 1
    ");
    $stmt->bind_param("i", $proyecto_id);
    $stmt->execute();
    $proyecto_sel = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($proyecto_sel) {
        $projectIdFolder = getProjectFolderId($proyecto_sel);      // ID como carpeta
        $projectSlugLegacy = getProjectSlugLegacy($proyecto_sel);  // fallback

      $stmt = $conn->prepare("
    SELECT c.*,
           d.nombre AS distrito_nombre
    FROM cronicas c
    LEFT JOIN distritos d ON d.id = c.distrito
    WHERE c.proyecto_id = ?
      AND (c.estado_registro IS NULL OR c.estado_registro = 'activo')
    ORDER BY c.fecha DESC, c.id DESC
");

        $stmt->bind_param("i", $proyecto_id);
        $stmt->execute();
        $rs = $stmt->get_result();
        while ($row = $rs->fetch_assoc()) $cronicas[] = $row;
        $stmt->close();
    }
}

include "../../includes/header.php";
?>

<div class="container py-4">

  <div class="row g-3 align-items-stretch mb-3">
    <div class="col-lg-8">
      <div class="card shadow-sm h-100">
        <div class="card-body">
          <div class="d-flex align-items-center justify-content-between flex-wrap gap-2">
            <div>
              <div class="h4 mb-1">Panel de visor</div>
              <div class="text-muted">
                Modo solo lectura: consulta proyectos, crónicas y evidencias sin modificar información.
              </div>
            </div>
            <div class="d-flex gap-2">
              <a href="../../index.php" class="btn btn-outline-secondary">
                <i class="bi bi-house-door"></i> Inicio
              </a>
            </div>
          </div>

          <hr>

          <div class="row g-2">
            <div class="col-md-9">
              <label class="form-label">Paso 1: Seleccione un proyecto</label>
              <form class="d-flex gap-2" method="get">
                <select name="proyecto_id" class="form-select form-select-lg" required>
                  <option value="">Seleccione...</option>
                  <?php if ($proyectos && $proyectos->num_rows > 0): ?>
                    <?php while ($p = $proyectos->fetch_assoc()): ?>
                      <option value="<?= (int)$p["id"]; ?>" <?= ($proyecto_id === (int)$p["id"]) ? "selected" : ""; ?>>
                        Proyecto #<?= (int)$p["id"]; ?> — <?= e($p["nombre"]); ?>
                        <?= !empty($p["distrito_nombre"]) ? (" (" . e($p["distrito_nombre"]) . ")") : ""; ?>
                      </option>
                    <?php endwhile; ?>
                  <?php endif; ?>
                </select>
                <button class="btn btn-primary btn-lg px-4" type="submit">
                  <i class="bi bi-search"></i> Consultar
                </button>
              </form>
              <div class="form-text">
                Consejo: si no aparece información, revise que el proyecto esté activo.
              </div>
            </div>

            <div class="col-md-3">
              <label class="form-label">Paso 2: Buscar en crónicas</label>
              <input id="buscadorCronicas" type="text" class="form-control form-control-lg"
                     placeholder="Ej: pendiente, Biolley, GV-..." <?= $proyecto_sel ? "" : "disabled"; ?>>
              <div class="form-text">
                Filtra por fecha, estado, distrito o texto.
              </div>
            </div>
          </div>

        </div>
      </div>
    </div>

    <div class="col-lg-4">
      <div class="card shadow-sm h-100">
        <div class="card-body">
          <div class="h6 text-muted mb-2">Perfil</div>
          <div class="d-flex align-items-center gap-3">
            <div class="rounded-circle bg-light d-flex align-items-center justify-content-center" style="width:54px;height:54px;">
              <i class="bi bi-person" style="font-size:26px;"></i>
            </div>
            <div>
              <div class="mb-1">Rol: visor</div>
              <div class="text-muted">Acceso: lectura completa</div>
            </div>
          </div>

          <hr>

          <div class="small text-muted">
            En este panel:
            <ul class="mb-0">
              <li>Se puede ver la evidencia fotográfica sin descargar.</li>
              <li>Se puede abrir la crónica completa.</li>
              <li>No hay opciones para editar o eliminar.</li>
            </ul>
          </div>
        </div>
      </div>
    </div>
  </div>

  <?php if ($proyecto_id > 0 && $proyecto_sel): ?>
    <?php
      $totalCronicas = count($cronicas);
      $estadoP = (string)($proyecto_sel["estado"] ?? "—");
      $encP = (string)($proyecto_sel["encargado_nombre"] ?? "—");
      $disP = (string)($proyecto_sel["distrito_nombre"] ?? "—");
      $nomP = (string)($proyecto_sel["nombre"] ?? ("Proyecto #" . (int)$proyecto_sel["id"]));
      $descP = trim((string)($proyecto_sel["descripcion"] ?? ""));
    ?>

    <div class="card shadow-sm mb-3">
      <div class="card-body">
        <div class="d-flex justify-content-between align-items-start flex-wrap gap-2">
          <div>
            <div class="h5 mb-1">Proyecto #<?= (int)$proyecto_sel["id"]; ?> — <?= e($nomP); ?></div>
            <div class="text-muted">Información general del proyecto seleccionado</div>
          </div>
        </div>

        <hr>

        <div class="row g-3">
          <div class="col-md-3">
            <div class="p-3 bg-light rounded">
              <div class="text-muted small">Distrito</div>
              <div class="fs-6"><?= e($disP); ?></div>
            </div>
          </div>
          <div class="col-md-3">
            <div class="p-3 bg-light rounded">
              <div class="text-muted small">Encargado</div>
              <div class="fs-6"><?= e($encP); ?></div>
            </div>
          </div>
          <div class="col-md-3">
            <div class="p-3 bg-light rounded">
              <div class="text-muted small">Estado</div>
              <div class="fs-6"><?= e($estadoP); ?></div>
            </div>
          </div>
          <div class="col-md-3">
            <div class="p-3 bg-light rounded">
              <div class="text-muted small">Crónicas registradas</div>
              <div class="fs-4"><?= (int)$totalCronicas; ?></div>
            </div>
          </div>
        </div>

        <?php if ($descP !== ''): ?>
          <hr>
          <div class="text-muted small mb-1">Descripción</div>
          <div><?= $proyecto_sel["descripcion"]; ?></div>
        <?php endif; ?>
      </div>
    </div>

    <div class="card shadow-sm">
      <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
        <div class="d-flex align-items-center gap-2">
          <i class="bi bi-journal-text"></i>
          <span>Crónicas del proyecto</span>
        </div>
        <span class="badge bg-secondary"><?= (int)$totalCronicas; ?> registro(s)</span>
      </div>

      <div class="card-body p-0">
        <?php if (!empty($cronicas)): ?>
          <div class="table-responsive">
            <table id="tablaCronicas" class="table table-hover align-middle mb-0">
              <thead class="table-light">
                <tr>
                  <th style="width:90px;">ID</th>
                  <th style="width:140px;">Fecha</th>
                  <th style="width:170px;">Estado</th>
                  <th style="width:220px;">Tipos</th>
                  <th style="width:170px;">Distrito</th>
                  <th>Evidencia</th>
                  <th style="width:160px;">Acciones</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($cronicas as $c): ?>
                  <?php
                    $fecha = !empty($c["fecha"]) ? date('d/m/Y', strtotime($c["fecha"])) : "—";
                    $estado = (string)($c["estado"] ?? "—");
                    $distrito = (string)($c["distrito_nombre"] ?? "—");
                    $tipos = tiposTexto($conn, $c["tipo"] ?? "[]");

                    $imagenesRaw = json_decode($c["imagenes"] ?? "[]", true);
                    if (!is_array($imagenesRaw)) $imagenesRaw = [];
                    $imagenesNorm = normalizarImagenes($imagenesRaw);

                    $imgsForViewer = [];
                    foreach ($imagenesNorm as $im) {
                      $file = (string)($im['file'] ?? '');
                      if (trim($file) === '') continue;
                      $desc = (string)($im['desc'] ?? '');
                      $resolved = resolverUrlImagen($projectIdFolder, $projectSlugLegacy, $file);
                      $imgsForViewer[] = [
                        'url'  => $resolved['url'],
                        'file' => $file,
                        'desc' => $desc
                      ];
                    }

                    $imgsB64 = base64_encode(json_encode($imgsForViewer, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
                    $resumen = resumenSeguro($c["comentarios"] ?? "", $c["observaciones"] ?? "");
                  ?>
                  <tr class="filaCronica">
                    <td>#<?= (int)$c["id"]; ?></td>
                    <td><?= e($fecha); ?></td>
                    <td><?= e($estado); ?></td>
                    <td><?= e($tipos); ?></td>
                    <td><?= e($distrito); ?></td>

                    <td>
                      <div class="d-flex align-items-center gap-2 flex-wrap">
                        <?php if (!empty($imgsForViewer)): ?>
                          <?php
                            $maxThumbs = 3;
                            $thumbs = array_slice($imgsForViewer, 0, $maxThumbs);
                            $extra = count($imgsForViewer) - count($thumbs);
                          ?>
                          <?php foreach ($thumbs as $t): ?>
                            <button type="button"
                                    class="btn p-0 border rounded overflow-hidden"
                                    style="width:64px;height:50px;"
                                    data-bs-toggle="modal"
                                    data-bs-target="#modalFotos"
                                    data-cronica-id="<?= (int)$c["id"]; ?>"
                                    data-fecha="<?= e($fecha); ?>"
                                    data-resumen="<?= e($resumen); ?>"
                                    data-images="<?= e($imgsB64); ?>">
                              <img src="<?= e($t['url']); ?>" alt=""
                                   style="width:64px;height:50px;object-fit:cover;display:block;">
                            </button>
                          <?php endforeach; ?>

                          <?php if ($extra > 0): ?>
                            <button type="button"
                                    class="btn btn-outline-secondary btn-sm"
                                    data-bs-toggle="modal"
                                    data-bs-target="#modalFotos"
                                    data-cronica-id="<?= (int)$c["id"]; ?>"
                                    data-fecha="<?= e($fecha); ?>"
                                    data-resumen="<?= e($resumen); ?>"
                                    data-images="<?= e($imgsB64); ?>">
                              +<?= (int)$extra; ?>
                            </button>
                          <?php endif; ?>
                        <?php else: ?>
                          <span class="text-muted">Sin fotos</span>
                        <?php endif; ?>
                      </div>
                    </td>

                    <td>
                      <div class="d-flex gap-2 flex-wrap">
                        <a href="../cronicas/cronica_detalle.php?id=<?= (int)$c["id"]; ?>"
                           class="btn btn-outline-primary btn-sm">
                          <i class="bi bi-box-arrow-up-right"></i> Ver crónica
                        </a>

                        <?php if (!empty($imgsForViewer)): ?>
                          <button type="button"
                                  class="btn btn-outline-secondary btn-sm"
                                  data-bs-toggle="modal"
                                  data-bs-target="#modalFotos"
                                  data-cronica-id="<?= (int)$c["id"]; ?>"
                                  data-fecha="<?= e($fecha); ?>"
                                  data-resumen="<?= e($resumen); ?>"
                                  data-images="<?= e($imgsB64); ?>">
                            <i class="bi bi-images"></i> Ver fotos
                          </button>
                        <?php endif; ?>
                      </div>
                    </td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>

          <div class="p-3 border-top bg-light">
            <div class="text-muted small">
              Puede tocar una miniatura para ver la foto en grande (no descarga nada).
            </div>
          </div>

        <?php else: ?>
          <div class="p-3">
            <div class="alert alert-warning mb-0">
              No hay crónicas registradas para este proyecto.
            </div>
          </div>
        <?php endif; ?>
      </div>
    </div>

  <?php else: ?>
    <div class="alert alert-secondary mt-3">
      Seleccione un proyecto para visualizar su información y sus crónicas.
    </div>
  <?php endif; ?>

</div>

<!-- MODAL: VISOR DE FOTOS -->
<div class="modal fade" id="modalFotos" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header">
        <div>
          <div class="h5 mb-1" id="modalFotosTitulo">Evidencia fotográfica</div>
          <div class="text-muted small" id="modalFotosSub">—</div>
        </div>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
      </div>

      <div class="modal-body">
        <div id="modalFotosResumen" class="alert alert-light border small mb-3" style="display:none;"></div>

        <div id="carouselFotos" class="carousel slide" data-bs-interval="false">
          <div class="carousel-inner" id="carouselFotosInner"></div>

          <button class="carousel-control-prev" type="button" data-bs-target="#carouselFotos" data-bs-slide="prev">
            <span class="carousel-control-prev-icon" aria-hidden="true"></span>
            <span class="visually-hidden">Anterior</span>
          </button>
          <button class="carousel-control-next" type="button" data-bs-target="#carouselFotos" data-bs-slide="next">
            <span class="carousel-control-next-icon" aria-hidden="true"></span>
            <span class="visually-hidden">Siguiente</span>
          </button>
        </div>

      </div>

      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cerrar</button>
      </div>
    </div>
  </div>
</div>

<script>
(function() {
  const input = document.getElementById('buscadorCronicas');
  const tabla = document.getElementById('tablaCronicas');

  function filtrarTabla() {
    if (!tabla || !input) return;
    const q = (input.value || '').toLowerCase().trim();
    const filas = tabla.querySelectorAll('tbody .filaCronica');
    filas.forEach(tr => {
      const txt = tr.innerText.toLowerCase();
      tr.style.display = txt.includes(q) ? '' : 'none';
    });
  }
  if (input) input.addEventListener('input', filtrarTabla);

  const modal = document.getElementById('modalFotos');
  if (!modal) return;

  modal.addEventListener('show.bs.modal', function(ev) {
    const btn = ev.relatedTarget;
    if (!btn) return;

    const cronicaId = btn.getAttribute('data-cronica-id') || '';
    const fecha     = btn.getAttribute('data-fecha') || '';
    const resumen   = btn.getAttribute('data-resumen') || '';
    const b64       = btn.getAttribute('data-images') || '';

    const sub    = document.getElementById('modalFotosSub');
    const resBox = document.getElementById('modalFotosResumen');
    const inner  = document.getElementById('carouselFotosInner');

    if (sub) sub.textContent = 'Crónica #' + cronicaId + ' — ' + fecha;

    if (resBox) {
      if (resumen && resumen.trim() !== '') {
        resBox.style.display = '';
        resBox.textContent = 'Resumen: ' + resumen;
      } else {
        resBox.style.display = 'none';
        resBox.textContent = '';
      }
    }

    if (inner) inner.innerHTML = '';

    let imgs = [];
    try {
      const json = atob(b64);
      imgs = JSON.parse(json);
      if (!Array.isArray(imgs)) imgs = [];
    } catch (e) {
      imgs = [];
    }

    if (!inner) return;

    if (imgs.length === 0) {
      inner.innerHTML = `
        <div class="carousel-item active">
          <div class="p-4 text-center text-muted">
            No hay imágenes para mostrar.
          </div>
        </div>`;
      return;
    }

    imgs.forEach((im, idx) => {
      const url  = (im && im.url) ? im.url : '';
      const file = (im && im.file) ? im.file : '';
      const desc = (im && im.desc) ? im.desc : '';

      const active = (idx === 0) ? 'active' : '';
      const caption = (desc && desc.trim() !== '')
        ? desc
        : (file ? ('Archivo: ' + file) : '');

      const slide = document.createElement('div');
      slide.className = 'carousel-item ' + active;
      slide.innerHTML = `
        <div class="d-flex justify-content-center">
          <img src="${url}" alt="" style="max-width:100%; max-height:65vh; object-fit:contain; border-radius:10px;">
        </div>
        <div class="mt-3 small text-muted">
          ${caption ? caption.replace(/</g,'&lt;').replace(/>/g,'&gt;') : ''}
        </div>
      `;
      inner.appendChild(slide);
    });
  });
})();
</script>

<?php include "../../includes/footer.php"; ?>


