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

// =====================================
// ROLES
// =====================================
$rol = strtolower(trim($_SESSION["rol"] ?? "vista"));
$uid = (int)($_SESSION["id"] ?? 0);

$puedeAnular = (function_exists('can_edit') && (can_edit("admin") || can_edit("cronicas"))) || ($rol === "admin");

$MOTIVOS_ANULACION = [
    "error_datos"          => "Error en datos (proyecto/distrito/encargado)",
    "evidencia_incorrecta" => "Evidencia incorrecta o incompleta",
    "duplicada"            => "Crónica duplicada",
    "proyecto_equivocado"  => "Registrada en el proyecto equivocado",
    "digitacion"           => "Error de digitación / estructura",
    "correccion_mayor"     => "Corrección mayor (se creará reemplazo)",
    "solicitud_admin"      => "Solicitud administrativa / encargado",
];

function e($s) {
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

function normalizarImagenes($raw): array {
    $out = [];
    if (!is_array($raw)) return $out;

    foreach ($raw as $item) {
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
        }
    }
    return $out;
}

function normalizarAdjuntos($raw): array {
    $out = [];
    if (is_string($raw)) {
        $raw = trim($raw);
        if ($raw === '') return [];
        return [$raw];
    }
    if (!is_array($raw)) return [];
    foreach ($raw as $it) {
        if (!is_string($it)) $it = (string)$it;
        $it = trim($it);
        if ($it === '') continue;
        $out[] = $it;
    }
    return $out;
}

// =====================================
// CARGAR CRÓNICA
// =====================================
$id = isset($_GET["id"]) ? (int)$_GET["id"] : 0;
if ($id <= 0) {
    header("Location: cronicas.php");
    exit;
}

// =====================================
// ANULAR (POST)
// =====================================
if ($_SERVER["REQUEST_METHOD"] === "POST" && ($_POST["accion"] ?? "") === "anular") {

    if (!$puedeAnular) {
        header("Location: cronica_detalle.php?id=".$id."&err=" . urlencode("Sin permisos para anular."));
        exit;
    }

    $motivo_key = trim((string)($_POST["motivo_anulacion"] ?? ""));
    $detalle    = trim((string)($_POST["detalle_anulacion"] ?? ""));

    if ($motivo_key === "" || !array_key_exists($motivo_key, $MOTIVOS_ANULACION)) {
        header("Location: cronica_detalle.php?id=".$id."&err=" . urlencode("Debe seleccionar un motivo válido."));
        exit;
    }

    if ($motivo_key === "correccion_mayor" && mb_strlen($detalle) < 10) {
        header("Location: cronica_detalle.php?id=".$id."&err=" . urlencode("En 'Corrección mayor' debe indicar un detalle (mínimo 10 caracteres)."));
        exit;
    }

    $stmtChk = $conn->prepare("SELECT estado_registro FROM cronicas WHERE id=? LIMIT 1");
    $stmtChk->bind_param("i", $id);
    $stmtChk->execute();
    $rowChk = $stmtChk->get_result()->fetch_assoc();
    $stmtChk->close();

    if (!$rowChk) {
        header("Location: cronicas.php?err=" . urlencode("Crónica no encontrada."));
        exit;
    }

    $estadoReg = strtolower(trim((string)($rowChk["estado_registro"] ?? "activo")));
    if ($estadoReg === "anulada") {
        header("Location: cronica_detalle.php?id=".$id."&ok=" . urlencode("La crónica ya estaba anulada."));
        exit;
    }

    $stmtAntes = $conn->prepare("SELECT * FROM cronicas WHERE id=? LIMIT 1");
    $stmtAntes->bind_param("i", $id);
    $stmtAntes->execute();
    $antes = $stmtAntes->get_result()->fetch_assoc();
    $stmtAntes->close();

    $motivo_label = $MOTIVOS_ANULACION[$motivo_key];

    $stmtUp = $conn->prepare("
        UPDATE cronicas
        SET
            estado_registro = 'anulada',
            anulada_por = ?,
            anulada_fecha = NOW(),
            anulada_motivo = ?,
            anulada_detalle = ?
        WHERE id = ?
        LIMIT 1
    ");
    $stmtUp->bind_param("issi", $uid, $motivo_label, $detalle, $id);
    $stmtUp->execute();
    $stmtUp->close();

    if (function_exists('log_accion')) {
        $detalle_log = json_encode([
            'cronica_id' => $id,
            'accion'     => 'anular',
            'antes'      => $antes,
            'despues'    => [
                'id'              => $id,
                'estado_registro' => 'anulada',
                'anulada_por'     => $uid,
                'anulada_motivo'  => $motivo_label,
                'anulada_detalle' => $detalle,
            ],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        log_accion($conn, 'CRONICA_ANULAR', $detalle_log);
    }

    header("Location: cronica_detalle.php?id=".$id."&ok=" . urlencode("Crónica anulada correctamente."));
    exit;
}

// =====================================
// CARGAR CRÓNICA
// =====================================
$stmt = $conn->prepare("
    SELECT
        c.*,
        p.nombre AS proyecto_nombre,
        e.nombre AS encargado_nombre,
        d.nombre AS distrito_nombre
    FROM cronicas c
    INNER JOIN proyectos p ON p.id = c.proyecto_id
    LEFT JOIN encargados e ON e.id = c.encargado
    LEFT JOIN distritos  d ON d.id = c.distrito
    WHERE c.id = ?
    LIMIT 1
");
$stmt->bind_param("i", $id);
$stmt->execute();
$res = $stmt->get_result();
$cronica = $res->fetch_assoc();
$stmt->close();

if (!$cronica) {
    header("Location: cronicas.php");
    exit;
}

$consecutivoBase = $cronica['consecutivo'] ?? '';
$anioConsec = !empty($cronica['fecha']) ? date('Y', strtotime($cronica['fecha'])) : date('Y');
$consecutivoMostrar = $consecutivoBase ? ($consecutivoBase . '-' . $anioConsec) : '';

$tiposArr = json_decode($cronica["tipo"] ?? "[]", true);
if (!is_array($tiposArr)) $tiposArr = [];
$tiposTexto = "-";
if (!empty($tiposArr)) {
    $ids = implode(",", array_map('intval', $tiposArr));
    $rsTipos = $conn->query("SELECT nombre FROM tipos_cronica WHERE id IN ($ids)");
    $nombres = [];
    while($rsTipos && ($r = $rsTipos->fetch_assoc())) $nombres[] = $r["nombre"];
    if (!empty($nombres)) $tiposTexto = implode(", ", $nombres);
}

$comentarios   = $cronica["comentarios"] ?? "";
$observaciones = trim($cronica["observaciones"] ?? "");

$imagenesRaw = json_decode($cronica["imagenes"]  ?? "[]", true);
if (!is_array($imagenesRaw)) $imagenesRaw = [];
$imagenes = normalizarImagenes($imagenesRaw);

$adjuntosRaw = json_decode($cronica["adjuntos"] ?? "[]", true);
$adjuntos = normalizarAdjuntos($adjuntosRaw);

$documentos = json_decode($cronica["documentos"] ?? "[]", true);
$firmados   = json_decode($cronica["firmados"]   ?? "[]", true);
if (!is_array($documentos)) $documentos = [];
if (!is_array($firmados))   $firmados   = [];

$proyectoIdFS = (int)($cronica['proyecto_id'] ?? 0);
if ($proyectoIdFS <= 0) $proyectoIdFS = 0;

$baseUrlProyecto = "/gestion_vial_ui/data/proyectos/" . $proyectoIdFS;
$urlImg   = $baseUrlProyecto . "/cronicas_img";
$urlAdj   = $baseUrlProyecto . "/cronicas_adjuntos";
$urlDocs  = $baseUrlProyecto . "/cronicas_docs";
$urlFirms = $baseUrlProyecto . "/cronicas_firmadas";

$estadoRegistro = strtolower(trim((string)($cronica["estado_registro"] ?? "activo")));
$estaAnulada = ($estadoRegistro === "anulada");

$mensaje_ok  = isset($_GET['ok'])  ? trim($_GET['ok'])  : '';
$mensaje_err = isset($_GET['err']) ? trim($_GET['err']) : '';

// =======================================================
// ARMAR LISTA UNIFICADA PARA CARRUSEL (evidencia+adjuntos)
// =======================================================
$carouselItems = [];
foreach ($imagenes as $img) {
    $file = trim((string)($img['file'] ?? ''));
    if ($file === '') continue;
    $desc = trim((string)($img['desc'] ?? ''));
    $href = $urlImg . '/' . rawurlencode($file);
    $carouselItems[] = ['src' => $href, 'desc' => $desc];
}
foreach ($adjuntos as $a) {
    $a = trim((string)$a);
    if ($a === '') continue;
    $ext = strtolower(pathinfo($a, PATHINFO_EXTENSION));
    $isImg = in_array($ext, ['jpg','jpeg','png','gif','webp'], true);
    if (!$isImg) continue;

    $href = $urlAdj . '/' . rawurlencode($a);
    $carouselItems[] = ['src' => $href, 'desc' => '']; // adjuntos sin desc
}
$carouselJsonB64 = base64_encode(json_encode($carouselItems, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

include "../../includes/header.php";
?>

<div class="container py-4">

  <?php if ($mensaje_ok !== ''): ?>
    <div class="alert alert-success alert-dismissible fade show shadow-sm" role="alert">
      <?= e($mensaje_ok) ?>
      <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Cerrar"></button>
    </div>
  <?php endif; ?>

  <?php if ($mensaje_err !== ''): ?>
    <div class="alert alert-danger alert-dismissible fade show shadow-sm" role="alert">
      <?= e($mensaje_err) ?>
      <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Cerrar"></button>
    </div>
  <?php endif; ?>

  <?php if ($estaAnulada): ?>
    <div class="alert alert-danger shadow-sm">
      Esta crónica está anulada.
      <div class="mt-2 small">
        Motivo: <?= e($cronica["anulada_motivo"] ?? "—") ?><br>
        Detalle: <?= e($cronica["anulada_detalle"] ?? "—") ?><br>
        Fecha: <?= !empty($cronica["anulada_fecha"]) ? e(date('d/m/Y H:i', strtotime($cronica["anulada_fecha"]))) : "—" ?>
      </div>
    </div>
  <?php endif; ?>

  <div class="d-flex justify-content-between align-items-center mb-3">
    <div>
      <h3 class="mb-0" style="color:#0d6efd;">
        Crónica <?= e($consecutivoMostrar ?: $consecutivoBase) ?>
      </h3>
      <div class="text-muted small">
        Estado: <?= e($cronica['estado'] ?? '—') ?> | Registro: <?= e($estadoRegistro) ?>
      </div>
    </div>

    <div class="d-flex gap-2 flex-wrap">
      <a href="cronicas.php" class="btn btn-secondary">Volver</a>
      <a href="cronicas_print.php?id=<?= (int)$cronica['id'] ?>" class="btn btn-dark" target="_blank" rel="noopener">Imprimir</a>

      <?php if (function_exists('can_edit') && (can_edit("admin") || can_edit("cronicas") || can_edit("cronicas_crear") || can_edit("cronicas_ver"))): ?>
        <a href="cronica_pdf.php?id=<?= (int)$cronica['id'] ?>" class="btn btn-outline-danger" target="_blank" rel="noopener">PDF</a>
      <?php endif; ?>

      <?php if ($puedeAnular && !$estaAnulada): ?>
        <button class="btn btn-outline-danger" data-bs-toggle="modal" data-bs-target="#modalAnular">Anular</button>
      <?php endif; ?>
    </div>
  </div>

  <div class="card shadow-sm mb-4">
    <div class="card-body">

      <div class="row mb-3">
        <div class="col-md-6">
          <div class="mb-1">Proyecto: <?= e($cronica['proyecto_nombre'] ?? '-') ?></div>
          <div class="mb-1">Tipos: <?= e($tiposTexto) ?></div>
        </div>
        <div class="col-md-6">
          <div class="mb-1">Encargado: <?= e($cronica['encargado_nombre'] ?? '-') ?></div>
          <div class="mb-1">Distrito: <?= e($cronica['distrito_nombre'] ?? '-') ?></div>
        </div>
      </div>

      <div class="row mb-3">
        <div class="col-md-6">
          <div class="mb-1">Estado: <?= e($cronica['estado'] ?? '-') ?></div>
        </div>
        <div class="col-md-6">
          <div class="mb-1">
            Fecha: <?= !empty($cronica['fecha']) ? e(date('d/m/Y', strtotime($cronica['fecha']))) : "-" ?>
          </div>
        </div>
      </div>

      <hr>

      <h5 class="mb-2">Comentarios (se imprimen)</h5>
      <div class="border rounded p-3 bg-light mb-4" style="min-height:120px;">
        <?= $comentarios !== '' ? $comentarios : '<span class="text-muted">Sin comentarios.</span>' ?>
      </div>

      <h5 id="observaciones" class="mb-2">Observaciones internas (no se imprimen)</h5>
      <div class="border rounded p-3 mb-4" style="min-height:80px;">
        <?php if ($observaciones !== ''): ?>
          <pre class="mb-0" style="white-space:pre-wrap; font-family:inherit;"><?= e($observaciones) ?></pre>
        <?php else: ?>
          <span class="text-muted">Sin observaciones internas.</span>
        <?php endif; ?>
      </div>

      <div class="row">
        <div class="col-md-6 mb-3">
          <h6 class="mb-2">Imágenes (evidencia)</h6>
          <?php if (!empty($imagenes)): ?>
            <div class="thumb-grid">
              <?php foreach ($imagenes as $img): ?>
                <?php
                  $file = trim((string)($img['file'] ?? ''));
                  if ($file === '') continue;
                  $desc = trim((string)($img['desc'] ?? ''));
                  $href = $urlImg . '/' . rawurlencode($file);
                ?>
                <div class="thumb-wrap">
                  <button
                    type="button"
                    class="thumb-btn"
                    data-bs-toggle="modal"
                    data-bs-target="#modalGaleria"
                    data-gallery="<?= e($carouselJsonB64) ?>"
                    data-src="<?= e($href) ?>"
                    aria-label="Ver imagen"
                  >
                    <img class="thumb" src="<?= e($href) ?>" alt="Evidencia" loading="lazy">
                  </button>

                  <?php if ($desc !== ''): ?>
                    <div class="file-meta">
                      <div class="desc"><?= e($desc) ?></div>
                    </div>
                  <?php endif; ?>
                </div>
              <?php endforeach; ?>
            </div>
          <?php else: ?>
            <span class="text-muted">Sin imágenes.</span>
          <?php endif; ?>
        </div>

        <div class="col-md-6 mb-3">
          <h6 class="mb-2">Adjuntos (imágenes/capturas)</h6>
          <?php if (!empty($adjuntos)): ?>
            <div class="thumb-grid">
              <?php foreach ($adjuntos as $a): ?>
                <?php
                  $a = trim((string)$a);
                  if ($a === '') continue;

                  $ext = strtolower(pathinfo($a, PATHINFO_EXTENSION));
                  $isImg = in_array($ext, ['jpg','jpeg','png','gif','webp'], true);

                  $href = $urlAdj . '/' . rawurlencode($a);
                ?>
                <div class="thumb-wrap">
                  <?php if ($isImg): ?>
                    <button
                      type="button"
                      class="thumb-btn"
                      data-bs-toggle="modal"
                      data-bs-target="#modalGaleria"
                      data-gallery="<?= e($carouselJsonB64) ?>"
                      data-src="<?= e($href) ?>"
                      aria-label="Ver adjunto"
                    >
                      <img class="thumb" src="<?= e($href) ?>" alt="Adjunto" loading="lazy">
                    </button>
                  <?php else: ?>
                    <a href="<?= e($href) ?>" target="_blank" rel="noopener" class="doc-pill">Abrir archivo</a>
                  <?php endif; ?>
                </div>
              <?php endforeach; ?>
            </div>
          <?php else: ?>
            <span class="text-muted">Sin adjuntos.</span>
          <?php endif; ?>
        </div>
      </div>

      <div class="row">
        <div class="col-md-6 mb-3">
          <h6 class="mb-2">Documentos</h6>
          <?php if (!empty($documentos)): ?>
            <ul class="list-unstyled mb-0">
              <?php foreach ($documentos as $doc): ?>
                <?php if (!is_string($doc)) continue; $doc = trim($doc); if ($doc === '') continue; ?>
                <li class="mb-1">
                  <a href="<?= $urlDocs . '/' . rawurlencode($doc) ?>" target="_blank" rel="noopener"><?= e($doc) ?></a>
                </li>
              <?php endforeach; ?>
            </ul>
          <?php else: ?>
            <span class="text-muted">Sin documentos.</span>
          <?php endif; ?>
        </div>

        <div class="col-md-6 mb-3">
          <h6 class="mb-2">Firmados (PDF)</h6>
          <?php if (!empty($firmados)): ?>
            <ul class="list-unstyled mb-0">
              <?php foreach ($firmados as $f): ?>
                <?php if (!is_string($f)) continue; $f = trim($f); if ($f === '') continue; ?>
                <li class="mb-1">
                  <a href="<?= $urlFirms . '/' . rawurlencode($f) ?>" target="_blank" rel="noopener"><?= e($f) ?></a>
                </li>
              <?php endforeach; ?>
            </ul>
          <?php else: ?>
            <span class="text-muted">Sin firmados.</span>
          <?php endif; ?>
        </div>
      </div>

    </div>
  </div>
</div>

<!-- ===================== MODAL GALERÍA (SIG/ANT) ===================== -->
<div class="modal fade" id="modalGaleria" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-fullscreen">
    <div class="modal-content">
      <div class="modal-header">
        <div class="h5 mb-0">Galería</div>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
      </div>

      <div class="modal-body p-0">
        <div class="gallery-wrap">
          <button type="button" class="nav-btn nav-left" id="btnPrev" aria-label="Anterior">
            ‹
          </button>

          <div class="img-area">
            <img id="galleryImg" src="" alt="">
          </div>

          <button type="button" class="nav-btn nav-right" id="btnNext" aria-label="Siguiente">
            ›
          </button>
        </div>

        <div id="galleryDesc" class="gallery-desc" style="display:none;"></div>
      </div>

      <div class="modal-footer justify-content-between">
        <div class="small text-muted" id="galleryCounter">—</div>
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cerrar</button>
      </div>
    </div>
  </div>
</div>

<style>
/* thumbs */
.thumb-grid{ display:grid; grid-template-columns:1fr; gap:10px; }
.thumb-wrap{
  border:1px solid rgba(0,0,0,.08);
  border-radius:10px;
  padding:8px;
  background:#fff;
  box-shadow:0 1px 6px rgba(0,0,0,.06);
}
.thumb{ width:100%; height:140px; object-fit:cover; border-radius:8px; display:block; background:#f8f9fa; }
.thumb-btn{ border:0; padding:0; background:transparent; width:100%; cursor:pointer; }
.thumb-btn:focus{ outline:2px solid rgba(13,110,253,.35); outline-offset:3px; border-radius:10px; }
.file-meta{ margin-top:6px; }
.desc{ font-size:.85rem; color:#6c757d; line-height:1.2; word-break:break-word; }
.doc-pill{
  display:inline-block; width:100%; text-align:center;
  padding:12px 12px; border-radius:10px;
  border:1px solid rgba(0,0,0,.12);
  text-decoration:none; background:#f8f9fa;
}

/* modal gallery big */
.gallery-wrap{
  position:relative;
  width:100%;
  height: calc(100vh - 120px);
  display:flex;
  align-items:center;
  justify-content:center;
  background:#0b0f14;
}
.img-area{
  width:100%;
  height:100%;
  display:flex;
  align-items:center;
  justify-content:center;
  padding:18px;
}
#galleryImg{
  max-width:100%;
  max-height:88vh;   /* MÁS GRANDE */
  object-fit:contain;
  border-radius:14px;
  box-shadow:0 10px 30px rgba(0,0,0,.35);
  background:#111;
}

/* buttons */
.nav-btn{
  position:absolute;
  top:50%;
  transform:translateY(-50%);
  width:64px;
  height:64px;
  border-radius:999px;
  border:1px solid rgba(255,255,255,.20);
  background:rgba(0,0,0,.30);
  color:#fff;
  font-size:44px;
  line-height:1;
  display:flex;
  align-items:center;
  justify-content:center;
  cursor:pointer;
  user-select:none;
}
.nav-btn:hover{ background:rgba(0,0,0,.50); }
.nav-left{ left:18px; }
.nav-right{ right:18px; }

.gallery-desc{
  padding:12px 16px;
  background:#fff;
  color:#6c757d;
  font-size:.95rem;
  border-top:1px solid rgba(0,0,0,.06);
}
</style>

<script>
(function(){
  const modal = document.getElementById('modalGaleria');
  if (!modal) return;

  const imgTag = document.getElementById('galleryImg');
  const descBox = document.getElementById('galleryDesc');
  const counter = document.getElementById('galleryCounter');
  const btnPrev = document.getElementById('btnPrev');
  const btnNext = document.getElementById('btnNext');

  let items = [];
  let idx = 0;

  function setView() {
    if (!imgTag) return;

    const it = items[idx] || null;
    const src = it && it.src ? it.src : '';
    const desc = it && it.desc ? String(it.desc) : '';

    imgTag.src = src;

    // NO mostrar nombre de archivo, solo desc si existe
    const t = (desc || '').trim();
    if (descBox) {
      if (t !== '') {
        descBox.style.display = '';
        descBox.textContent = t;
      } else {
        descBox.style.display = 'none';
        descBox.textContent = '';
      }
    }

    if (counter) {
      if (items.length > 0) counter.textContent = (idx + 1) + ' / ' + items.length;
      else counter.textContent = '—';
    }

    // esconder nav si solo hay 1
    const showNav = items.length > 1;
    if (btnPrev) btnPrev.style.display = showNav ? '' : 'none';
    if (btnNext) btnNext.style.display = showNav ? '' : 'none';
  }

  function prev(){
    if (items.length <= 1) return;
    idx = (idx - 1 + items.length) % items.length;
    setView();
  }
  function next(){
    if (items.length <= 1) return;
    idx = (idx + 1) % items.length;
    setView();
  }

  if (btnPrev) btnPrev.addEventListener('click', prev);
  if (btnNext) btnNext.addEventListener('click', next);

  // teclado
  document.addEventListener('keydown', function(e){
    const isOpen = modal.classList.contains('show');
    if (!isOpen) return;
    if (e.key === 'ArrowLeft') prev();
    if (e.key === 'ArrowRight') next();
  });

  modal.addEventListener('show.bs.modal', function(ev){
    const btn = ev.relatedTarget;
    if (!btn) return;

    const b64 = btn.getAttribute('data-gallery') || '';
    const clickedSrc = btn.getAttribute('data-src') || '';

    items = [];
    idx = 0;

    try{
      const json = atob(b64);
      const arr = JSON.parse(json);
      if (Array.isArray(arr)) items = arr;
    }catch(e){
      items = [];
    }

    // Buscar índice del que se clickeó
    if (clickedSrc && items.length > 0) {
      const found = items.findIndex(x => x && x.src === clickedSrc);
      idx = (found >= 0) ? found : 0;
    }

    setView();
  });

  modal.addEventListener('hidden.bs.modal', function(){
    items = [];
    idx = 0;
    if (imgTag) imgTag.src = '';
    if (descBox) { descBox.style.display='none'; descBox.textContent=''; }
    if (counter) counter.textContent = '—';
  });
})();
</script>

<?php if ($puedeAnular && !$estaAnulada): ?>
<div class="modal fade" id="modalAnular" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header bg-danger text-white">
        <h5 class="modal-title">Anular crónica</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>

      <form method="POST" novalidate>
        <input type="hidden" name="accion" value="anular">

        <div class="modal-body">
          <div class="alert alert-warning">
            Esta acción no elimina la crónica. La marca como anulada y quedará solo para consulta.
          </div>

          <div class="mb-3">
            <label class="form-label">Motivo (obligatorio)</label>
            <select name="motivo_anulacion" class="form-select" required>
              <option value="">Seleccione...</option>
              <?php foreach ($MOTIVOS_ANULACION as $k => $label): ?>
                <option value="<?= e($k) ?>"><?= e($label) ?></option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="mb-3">
            <label class="form-label">Detalle (recomendado)</label>
            <textarea name="detalle_anulacion" class="form-control" rows="3" placeholder="Explique brevemente el motivo (si aplica)"></textarea>
            <div class="form-text">Si selecciona “Corrección mayor”, el detalle es obligatorio.</div>
          </div>

          <div class="form-check">
            <input class="form-check-input" type="checkbox" value="1" id="confirmAnular" required>
            <label class="form-check-label" for="confirmAnular">
              Confirmo que esta crónica quedará anulada y no se podrá modificar.
            </label>
          </div>
        </div>

        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
          <button type="submit" class="btn btn-danger">Anular crónica</button>
        </div>
      </form>
    </div>
  </div>
</div>
<?php endif; ?>

<?php include "../../includes/footer.php"; ?>
