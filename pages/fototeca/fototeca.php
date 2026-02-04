<?php
ini_set('default_charset','UTF-8');
mb_internal_encoding("UTF-8");
setlocale(LC_ALL,'es_ES.UTF-8');

require_once "../../config/db.php";
require_once "../../auth.php";
require_login();

header('Content-Type: text/html; charset=utf-8');
mysqli_set_charset($conn, "utf8");

error_reporting(E_ALL);
ini_set('display_errors', 1);

// ============================
// Permisos
// ============================
$rol = strtolower(trim($_SESSION["rol"] ?? "vista"));
$uid = (int)($_SESSION["id"] ?? 0);

// Ajustá aquí: si tu rol se llama "visor" en vez de "vista", cambiá la condición.
$puedeVer = ($rol === "admin" || $rol === "vista" || $rol === "visor");
if (!$puedeVer) {
  http_response_code(403);
  echo "Acceso denegado.";
  exit;
}

// ============================
// Helpers
// ============================
function e($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

function getDataBasePath(): ?string {
  $rootGuess = __DIR__ . DIRECTORY_SEPARATOR . ".." . DIRECTORY_SEPARATOR . ".."; // .../gestion_vial_ui
  $root = realpath($rootGuess);
  if ($root === false) $root = $rootGuess;

  $data = rtrim($root, "/\\") . DIRECTORY_SEPARATOR . "data";
  $proyectos = $data . DIRECTORY_SEPARATOR . "proyectos";

  if (!is_dir($data)) @mkdir($data, 0775, true);
  if (!is_dir($proyectos)) @mkdir($proyectos, 0775, true);

  if (!is_dir($data) || !is_dir($proyectos)) return null;
  return $data;
}

function safeJoin($base, $path) {
  $base = rtrim($base, "/\\");
  $path = str_replace(["..", "\0"], "", (string)$path);
  return $base . DIRECTORY_SEPARATOR . ltrim($path, "/\\");
}

// Construye URL para servir imagen por endpoint seguro
function fotoUrl($cronicaId, $tipo, $file) {
  return "../../api/foto_get.php?cronica_id=" . urlencode((string)$cronicaId)
    . "&tipo=" . urlencode((string)$tipo)
    . "&file=" . urlencode((string)$file);
}

// ============================
// Filtros
// ============================
$proyecto_id = (int)($_GET["proyecto_id"] ?? 0);
$q = trim((string)($_GET["q"] ?? ""));
$verAdjuntos = (int)($_GET["adj"] ?? 0); // 0 solo evidencia, 1 incluye adjuntos

$page = max(1, (int)($_GET["page"] ?? 1));
$perPage = 24;
$offset = ($page - 1) * $perPage;

// lista proyectos para filtro
$proyRs = $conn->query("
  SELECT
    p.id,
    CONCAT(COALESCE(cam.codigo,''),' - ',COALESCE(p.nombre,'')) AS display_nombre
  FROM proyectos p
  LEFT JOIN caminos cam ON cam.id = p.inventario_id
  WHERE p.estado <> '0'
  ORDER BY cam.codigo, p.nombre
");
$listaProyectos = $proyRs ? $proyRs->fetch_all(MYSQLI_ASSOC) : [];

// ============================
// Query base: traemos crónicas con imagenes != []
// ============================
$where = "c.estado_registro='activo'"; // solo activas, ajustá si querés incluir anuladas
$params = [];
$types = "";

if ($proyecto_id > 0) {
  $where .= " AND c.proyecto_id = ?";
  $types .= "i";
  $params[] = $proyecto_id;
}

if ($q !== "") {
  $where .= " AND (
    c.consecutivo LIKE ?
    OR p.nombre LIKE ?
    OR cam.codigo LIKE ?
    OR c.comentarios LIKE ?
    OR c.observaciones LIKE ?
  )";
  $types .= "sssss";
  $like = "%" . $q . "%";
  $params[] = $like;
  $params[] = $like;
  $params[] = $like;
  $params[] = $like;
  $params[] = $like;
}

// Para conteo (paginación) contamos crónicas candidatas (no fotos exactas)
$sqlCount = "
  SELECT COUNT(*) AS total
  FROM cronicas c
  INNER JOIN proyectos p ON p.id = c.proyecto_id
  LEFT JOIN caminos cam ON cam.id = p.inventario_id
  WHERE $where
";
$stmtC = $conn->prepare($sqlCount);
if ($types !== "") $stmtC->bind_param($types, ...$params);
$stmtC->execute();
$totalRows = (int)($stmtC->get_result()->fetch_assoc()["total"] ?? 0);
$stmtC->close();

$sql = "
  SELECT
    c.id, c.proyecto_id, c.consecutivo, c.fecha, c.imagenes, c.adjuntos,
    p.nombre AS proyecto_nombre,
    COALESCE(cam.codigo,'') AS camino_codigo
  FROM cronicas c
  INNER JOIN proyectos p ON p.id = c.proyecto_id
  LEFT JOIN caminos cam ON cam.id = p.inventario_id
  WHERE $where
  ORDER BY c.id DESC
  LIMIT $perPage OFFSET $offset
";
$stmt = $conn->prepare($sql);
if ($types !== "") $stmt->bind_param($types, ...$params);
$stmt->execute();
$rs = $stmt->get_result();
$rows = $rs ? $rs->fetch_all(MYSQLI_ASSOC) : [];
$stmt->close();

// ============================
// Expandimos a lista de fotos (desde JSON)
// ============================
$fotos = [];
foreach ($rows as $r) {
  $imagenes = json_decode($r["imagenes"] ?? "[]", true);
  if (!is_array($imagenes)) $imagenes = [];

  foreach ($imagenes as $img) {
    $file = is_array($img) ? ($img["file"] ?? "") : "";
    $desc = is_array($img) ? ($img["desc"] ?? "") : "";
    $file = trim((string)$file);
    if ($file === "") continue;

    $fotos[] = [
      "tipo" => "evidencia",
      "cronica_id" => (int)$r["id"],
      "proyecto_id" => (int)$r["proyecto_id"],
      "file" => $file,
      "desc" => (string)$desc,
      "consecutivo" => (string)$r["consecutivo"],
      "fecha" => (string)$r["fecha"],
      "proyecto_nombre" => (string)$r["proyecto_nombre"],
      "camino_codigo" => (string)$r["camino_codigo"],
    ];
  }

  if ($verAdjuntos === 1) {
    $adj = json_decode($r["adjuntos"] ?? "[]", true);
    if (!is_array($adj)) $adj = [];
    foreach ($adj as $file) {
      $file = trim((string)$file);
      if ($file === "") continue;

      $fotos[] = [
        "tipo" => "adjunto",
        "cronica_id" => (int)$r["id"],
        "proyecto_id" => (int)$r["proyecto_id"],
        "file" => $file,
        "desc" => "",
        "consecutivo" => (string)$r["consecutivo"],
        "fecha" => (string)$r["fecha"],
        "proyecto_nombre" => (string)$r["proyecto_nombre"],
        "camino_codigo" => (string)$r["camino_codigo"],
      ];
    }
  }
}

$totalPages = max(1, (int)ceil($totalRows / $perPage));

include "../../includes/header.php";
?>
<div class="container py-4">

  <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
    <h3 class="mb-0 text-primary">
      <i class="bi bi-images"></i> Fototeca de Proyectos
    </h3>

    <div class="text-muted">
      Mostrando <?= (int)count($fotos) ?> foto(s) en esta página
    </div>
  </div>

  <div class="card shadow-sm mb-3">
    <div class="card-body">
      <form class="row g-2 align-items-end" method="GET">
        <div class="col-md-5">
          <label class="form-label">Proyecto</label>
          <select class="form-select" name="proyecto_id">
            <option value="0">Todos</option>
            <?php foreach ($listaProyectos as $p): ?>
              <option value="<?= (int)$p["id"] ?>" <?= ((int)$p["id"] === $proyecto_id ? "selected" : "") ?>>
                <?= e($p["display_nombre"]) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="col-md-5">
          <label class="form-label">Buscar</label>
          <input class="form-control" name="q" value="<?= e($q) ?>" placeholder="Consecutivo, nombre del proyecto, código de camino, texto...">
        </div>

        <div class="col-md-2">
          <label class="form-label">Incluir adjuntos</label>
          <select class="form-select" name="adj">
            <option value="0" <?= ($verAdjuntos===0 ? "selected" : "") ?>>No</option>
            <option value="1" <?= ($verAdjuntos===1 ? "selected" : "") ?>>Sí</option>
          </select>
        </div>

        <div class="col-12 d-flex gap-2 mt-2">
          <button class="btn btn-primary" type="submit">
            <i class="bi bi-search"></i> Filtrar
          </button>
          <a class="btn btn-outline-secondary" href="fototeca.php">
            <i class="bi bi-x-circle"></i> Limpiar
          </a>
        </div>
      </form>
    </div>
  </div>

  <?php if (count($fotos) === 0): ?>
    <div class="alert alert-info shadow-sm">
      No hay fotos para mostrar con los filtros actuales.
    </div>
  <?php else: ?>
    <div class="row g-3" id="gridFotos">
      <?php foreach ($fotos as $i => $f): 
        $url = fotoUrl($f["cronica_id"], $f["tipo"], $f["file"]);
        $titulo = trim($f["camino_codigo"] . " - " . $f["proyecto_nombre"]);
        $sub = $f["consecutivo"] . " · " . (empty($f["fecha"]) ? "" : date("d/m/Y", strtotime($f["fecha"])));
      ?>
        <div class="col-12 col-sm-6 col-md-4 col-lg-3">
          <div class="card h-100 shadow-sm border-0">
            <div class="ratio ratio-4x3 bg-light">
              <img
                src="<?= e($url) ?>"
                class="img-fluid rounded-top"
                style="object-fit: cover; width: 100%; height: 100%;"
                loading="lazy"
                alt="foto"
                data-bs-toggle="modal"
                data-bs-target="#modalFoto"
                data-index="<?= (int)$i ?>"
              >
            </div>

            <div class="card-body">
              <div class="small text-muted mb-1"><?= e($sub) ?></div>
              <div class="fw-semibold" style="font-size: 0.95rem; line-height: 1.2;">
                <?= e($titulo) ?>
              </div>

              <?php if (trim((string)$f["desc"]) !== ""): ?>
                <div class="text-muted small mt-2" style="min-height: 36px;">
                  <?= e($f["desc"]) ?>
                </div>
              <?php else: ?>
                <div class="text-muted small mt-2" style="min-height: 36px;">
                  <?= ($f["tipo"] === "adjunto" ? "Adjunto" : "Sin descripción") ?>
                </div>
              <?php endif; ?>

              <div class="d-flex justify-content-between align-items-center mt-3">
                <span class="badge bg-secondary"><?= e($f["tipo"]) ?></span>
                <a class="btn btn-outline-primary btn-sm" href="../../pages/cronicas/cronica_detalle.php?id=<?= (int)$f["cronica_id"] ?>" target="_blank">
                  Ver crónica
                </a>
              </div>
            </div>
          </div>
        </div>
      <?php endforeach; ?>
    </div>

    <nav class="mt-4">
      <ul class="pagination justify-content-center flex-wrap">
        <?php
          $base = "fototeca.php?proyecto_id=" . urlencode((string)$proyecto_id)
                . "&q=" . urlencode($q)
                . "&adj=" . urlencode((string)$verAdjuntos);
          $prev = max(1, $page - 1);
          $next = min($totalPages, $page + 1);
        ?>
        <li class="page-item <?= ($page<=1 ? "disabled" : "") ?>">
          <a class="page-link" href="<?= e($base . "&page=" . $prev) ?>">Anterior</a>
        </li>

        <?php
          $start = max(1, $page - 2);
          $end = min($totalPages, $page + 2);
          for ($p=$start; $p<=$end; $p++):
        ?>
          <li class="page-item <?= ($p===$page ? "active" : "") ?>">
            <a class="page-link" href="<?= e($base . "&page=" . $p) ?>"><?= (int)$p ?></a>
          </li>
        <?php endfor; ?>

        <li class="page-item <?= ($page>=$totalPages ? "disabled" : "") ?>">
          <a class="page-link" href="<?= e($base . "&page=" . $next) ?>">Siguiente</a>
        </li>
      </ul>
    </nav>
  <?php endif; ?>

</div>

<!-- Modal Lightbox -->
<div class="modal fade" id="modalFoto" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-xl modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header">
        <div class="modal-title" id="fotoTitle">Foto</div>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>

      <div class="modal-body">
        <div class="d-flex justify-content-center">
          <img id="fotoImg" src="" style="max-height: 70vh; width: 100%; object-fit: contain;" alt="foto grande">
        </div>

        <div class="mt-3 text-muted small" id="fotoMeta"></div>
        <div class="mt-2" id="fotoDesc"></div>
      </div>

      <div class="modal-footer d-flex justify-content-between">
        <button class="btn btn-outline-secondary" id="btnPrev"><i class="bi bi-chevron-left"></i> Anterior</button>
        <div class="d-flex gap-2">
          <a class="btn btn-outline-primary" id="btnVerCronica" target="_blank">Ver crónica</a>
          <button class="btn btn-outline-secondary" id="btnNext">Siguiente <i class="bi bi-chevron-right"></i></button>
        </div>
      </div>
    </div>
  </div>
</div>

<script>
  const fotos = <?= json_encode($fotos, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;

  const modal = document.getElementById('modalFoto');
  const fotoImg = document.getElementById('fotoImg');
  const fotoTitle = document.getElementById('fotoTitle');
  const fotoMeta = document.getElementById('fotoMeta');
  const fotoDesc = document.getElementById('fotoDesc');
  const btnPrev = document.getElementById('btnPrev');
  const btnNext = document.getElementById('btnNext');
  const btnVerCronica = document.getElementById('btnVerCronica');

  let idxActual = 0;

  function buildUrl(item){
    const url = "../../api/foto_get.php?cronica_id=" + encodeURIComponent(item.cronica_id)
      + "&tipo=" + encodeURIComponent(item.tipo)
      + "&file=" + encodeURIComponent(item.file);
    return url;
  }

  function render(i){
    if (!fotos || fotos.length === 0) return;
    idxActual = Math.max(0, Math.min(fotos.length - 1, i));
    const f = fotos[idxActual];

    fotoImg.src = buildUrl(f);

    const titulo = (f.camino_codigo ? (f.camino_codigo + " - ") : "") + (f.proyecto_nombre || "");
    fotoTitle.textContent = titulo || "Foto";

    const fecha = f.fecha ? new Date(f.fecha).toLocaleDateString() : "";
    fotoMeta.textContent = (f.consecutivo || "") + (fecha ? (" · " + fecha) : "") + " · " + (f.tipo || "");

    fotoDesc.textContent = (f.desc && String(f.desc).trim() !== "") ? f.desc : (f.tipo === "adjunto" ? "Adjunto" : "Sin descripción");

    btnVerCronica.href = "../../pages/cronicas/cronica_detalle.php?id=" + encodeURIComponent(f.cronica_id);

    btnPrev.disabled = (idxActual <= 0);
    btnNext.disabled = (idxActual >= fotos.length - 1);
  }

  document.addEventListener('click', function(ev){
    const img = ev.target.closest('img[data-index]');
    if (!img) return;
    const i = parseInt(img.getAttribute('data-index') || '0', 10);
    render(i);
  });

  btnPrev.addEventListener('click', () => render(idxActual - 1));
  btnNext.addEventListener('click', () => render(idxActual + 1));

  document.addEventListener('keydown', function(ev){
    if (!modal.classList.contains('show')) return;
    if (ev.key === 'ArrowLeft') render(idxActual - 1);
    if (ev.key === 'ArrowRight') render(idxActual + 1);
  });
</script>

<?php include "../../includes/footer.php"; ?>
