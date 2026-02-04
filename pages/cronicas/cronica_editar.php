<?php
ini_set('default_charset','UTF-8');
mb_internal_encoding("UTF-8");
setlocale(LC_ALL,'es_ES.UTF-8');

error_reporting(E_ALL);
ini_set('display_errors', 1);
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

require_once "../../config/db.php";
require_once "../../auth.php";
require_login();

header('Content-Type: text/html; charset=utf-8');
mysqli_set_charset($conn, "utf8");

$id = intval($_GET["id"] ?? 0);
if ($id <= 0) {
    include "../../includes/header.php";
    echo "<div class='alert alert-danger m-3'>❌ ID de crónica inválido.</div>";
    include "../../includes/footer.php";
    exit;
}

// =============================
// PERMISOS
// =============================
$rol         = $_SESSION["rol"] ?? "vista";
$puedeEditar = function_exists('can_edit') ? can_edit("cronicas") : false;

if (!$puedeEditar) {
    include "../../includes/header.php";
    echo "<div class='alert alert-danger m-3'>❌ No tiene permisos para editar crónicas.</div>";
    include "../../includes/footer.php";
    exit;
}

// =============================
// HELPERS
// =============================
function e($s): string {
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

function slugify_simple($s): string {
    $s = (string)$s;
    $s = trim($s);
    if ($s === '') return 'proyecto';
    $s = preg_replace('/[^A-Za-z0-9_\-]/', '_', $s);
    $s = preg_replace('/_+/', '_', $s);
    return trim($s, '_');
}

/**
 * Soporta:
 * - viejo: ["a.jpg","b.jpg"]
 * - nuevo: [{"file":"a.jpg","desc":"..."}, ...]
 * Devuelve: [ ["file"=>"a.jpg","desc"=>"..."], ... ]
 */
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

function normalizarListaStrings($raw): array {
    if (is_string($raw)) {
        $raw = trim($raw);
        return $raw === '' ? [] : [$raw];
    }
    if (!is_array($raw)) return [];
    $out = [];
    foreach ($raw as $it) {
        $it = trim((string)$it);
        if ($it !== '') $out[] = $it;
    }
    return $out;
}

// =============================
// CARGAR CRÓNICA
// =============================
$cronica = $conn->query("SELECT * FROM cronicas WHERE id = $id LIMIT 1")->fetch_assoc();
if (!$cronica) {
    include "../../includes/header.php";
    echo "<div class='alert alert-danger m-3'>❌ No se encontró la crónica.</div>";
    include "../../includes/footer.php";
    exit;
}

// Bloqueo si está finalizada y no es admin
if (($cronica["estado"] ?? '') === "Finalizado" && strtolower((string)$rol) !== "admin") {
    include "../../includes/header.php";
    ?>
    <div class="container py-4">
      <div class="alert alert-warning">
        ⚠️ Esta crónica está en estado <strong>Finalizado</strong>.<br>
        Solo un <strong>administrador</strong> puede modificarla.
      </div>
      <a href="cronicas.php" class="btn btn-secondary">
        <i class="bi bi-arrow-left"></i> Volver al listado
      </a>
    </div>
    <?php
    include "../../includes/footer.php";
    exit;
}

// =============================
// LISTAS
// =============================
$encargados = $conn->query("SELECT id,nombre FROM encargados WHERE activo=1 ORDER BY nombre");
$distritos  = $conn->query("SELECT id,nombre FROM distritos WHERE activo=1 ORDER BY nombre");
$tipos      = $conn->query("SELECT id,nombre FROM tipos_cronica ORDER BY nombre");

$listaTipos  = $tipos ? $tipos->fetch_all(MYSQLI_ASSOC) : [];
$tiposActual = json_decode($cronica["tipo"] ?? "[]", true);
if (!is_array($tiposActual)) $tiposActual = [];

// =============================
// PROYECTO (solo lectura)
// =============================
$proyecto_id = (int)($cronica["proyecto_id"] ?? 0);
$nombreProyectoActual = "Proyecto #" . $proyecto_id;

$rsNom = $conn->query("SELECT nombre FROM proyectos WHERE id=" . $proyecto_id);
if ($rsNom && ($rowNom = $rsNom->fetch_assoc())) {
    if (!empty($rowNom["nombre"])) $nombreProyectoActual = $rowNom["nombre"];
}

// Legacy slug (solo fallback para lectura)
$proyectoSlugLegacy = slugify_simple($nombreProyectoActual);

// =============================
// RUTAS (ID NUEVO + FALLBACK LEGACY)
// =============================
$baseDataFs = realpath(__DIR__ . "/../../data");
if ($baseDataFs === false) $baseDataFs = __DIR__ . "/../../data";

// NUEVO: carpeta por ID
$baseProyectoFs     = rtrim($baseDataFs, "/\\") . DIRECTORY_SEPARATOR . "proyectos" . DIRECTORY_SEPARATOR . $proyecto_id;
$rutaImgProyectoFs  = $baseProyectoFs . DIRECTORY_SEPARATOR . "cronicas_img";
$rutaDocsProyectoFs = $baseProyectoFs . DIRECTORY_SEPARATOR . "cronicas_docs";
$rutaFirmadosFs     = $baseProyectoFs . DIRECTORY_SEPARATOR . "cronicas_firmadas";

// LEGACY: carpeta por slug (solo lectura/fallback)
$baseProyectoLegacyFs    = rtrim($baseDataFs, "/\\") . DIRECTORY_SEPARATOR . "proyectos" . DIRECTORY_SEPARATOR . $proyectoSlugLegacy;
$rutaImgProyectoLegacyFs = $baseProyectoLegacyFs . DIRECTORY_SEPARATOR . "cronicas_img";

// Asegurar dirs NUEVOS
if (!is_dir($baseProyectoFs))     @mkdir($baseProyectoFs,     0777, true);
if (!is_dir($rutaImgProyectoFs))  @mkdir($rutaImgProyectoFs,  0777, true);
if (!is_dir($rutaDocsProyectoFs)) @mkdir($rutaDocsProyectoFs, 0777, true);
if (!is_dir($rutaFirmadosFs))     @mkdir($rutaFirmadosFs,     0777, true);

// Web paths NUEVOS
$baseProyectoWeb = "../../data/proyectos/" . rawurlencode((string)$proyecto_id);
$baseFirmadosWeb = $baseProyectoWeb . "/cronicas_firmadas/";

// =============================
// SUBIR ARCHIVOS
// =============================
if (!function_exists('subirArchivos')) {
    function subirArchivos($files, $destDir, $permitidas) {
        $guardados = [];

        if (!isset($files["name"]) || empty($files["name"]) || (is_array($files["name"]) && empty($files["name"][0]))) {
            return $guardados;
        }

        if (!is_dir($destDir)) @mkdir($destDir, 0777, true);

        $names = is_array($files["name"]) ? $files["name"] : [$files["name"]];
        foreach ($names as $i => $name) {
            $tmp = is_array($files["tmp_name"]) ? ($files["tmp_name"][$i] ?? '') : ($files["tmp_name"] ?? '');
            if ($tmp === '' || !is_uploaded_file($tmp)) continue;

            $ext = strtolower(pathinfo((string)$name, PATHINFO_EXTENSION));
            if (!in_array($ext, $permitidas, true)) continue;

            $seguro = time() . "_" . preg_replace("/[^A-Za-z0-9_\-\.]/", "_", (string)$name);
            $dest = rtrim($destDir, "/\\") . DIRECTORY_SEPARATOR . $seguro;

            if (move_uploaded_file($tmp, $dest)) {
                $guardados[] = $seguro;
            }
        }
        return $guardados;
    }
}

// =============================
// ACTUALIZAR CRÓNICA
// =============================
if ($_SERVER["REQUEST_METHOD"] === "POST" && ($_POST["accion"] ?? '') === "editar") {

    $encargado_id = intval($_POST["encargado_id"] ?? 0);
    $distrito_id  = intval($_POST["distrito_id"] ?? 0);
    $estado       = trim((string)($_POST["estado"] ?? 'Pendiente'));

    $tipos = $_POST["tipo_id"] ?? [];
    if (!is_array($tipos)) $tipos = [];
    $comentarios  = trim((string)($_POST["comentarios"] ?? ''));
    $tipos_json   = json_encode($tipos, JSON_UNESCAPED_UNICODE);

    $stmt = $conn->prepare("UPDATE cronicas
        SET encargado = ?, distrito = ?, estado = ?, tipo = ?, comentarios = ?
        WHERE id = ?");
    $stmt->bind_param("iisssi", $encargado_id, $distrito_id, $estado, $tipos_json, $comentarios, $id);
    $stmt->execute();
    $stmt->close();

    // Guardar SIEMPRE en ruta NUEVA por ID
    $imgDir = $rutaImgProyectoFs;
    $docDir = $rutaDocsProyectoFs;

    $imgsNuevas     = subirArchivos($_FILES["imagenes"]   ?? [], $imgDir,         ["jpg","jpeg","png","gif","webp"]);
    $docsNuevos     = subirArchivos($_FILES["documentos"] ?? [], $docDir,         ["pdf","doc","docx","xls","xlsx","ppt","pptx"]);
    $firmadosNuevos = subirArchivos($_FILES["firmados"]   ?? [], $rutaFirmadosFs, ["pdf"]);

    if (!empty($imgsNuevas) || !empty($docsNuevos) || !empty($firmadosNuevos)) {

        $prev = $conn->query("SELECT imagenes, documentos, firmados FROM cronicas WHERE id = $id")->fetch_assoc();

        $arrImgPrevRaw = json_decode($prev["imagenes"] ?? "[]", true);
        $arrImgPrev = normalizarImagenes(is_array($arrImgPrevRaw) ? $arrImgPrevRaw : []);

        $nuevasImgs = [];
        foreach ($imgsNuevas as $fn) {
            $fn = trim((string)$fn);
            if ($fn === '') continue;
            $nuevasImgs[] = ['file' => $fn, 'desc' => ''];
        }
        $arrImg = array_merge($arrImgPrev, $nuevasImgs);

        $arrDoc  = json_decode($prev["documentos"] ?? "[]", true);
        $arrFirm = json_decode($prev["firmados"]   ?? "[]", true);

        $arrDoc  = normalizarListaStrings($arrDoc);
        $arrFirm = normalizarListaStrings($arrFirm);

        $arrDoc  = array_merge($arrDoc,  $docsNuevos);
        $arrFirm = array_merge($arrFirm, $firmadosNuevos);

        $imgJson  = json_encode($arrImg,  JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $docJson  = json_encode($arrDoc,  JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $firmJson = json_encode($arrFirm, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $stmt2 = $conn->prepare("UPDATE cronicas SET imagenes = ?, documentos = ?, firmados = ? WHERE id = ?");
        $stmt2->bind_param("sssi", $imgJson, $docJson, $firmJson, $id);
        $stmt2->execute();
        $stmt2->close();
    }

    header("Location: cronicas.php?msg=editada");
    exit;
}

// =============================
// RESOLVER THUMB (NUEVO ID > LEGACY SLUG > GLOBAL)
// =============================
function resolverThumbImagen($proyecto_id, $proyectoSlugLegacy, $file): string {
    $file = (string)$file;

    $fsById    = __DIR__ . "/../../data/proyectos/" . $proyecto_id . "/cronicas_img/" . $file;
    $webById   = "../../data/proyectos/" . rawurlencode((string)$proyecto_id) . "/cronicas_img/" . rawurlencode($file);

    $fsLegacy  = __DIR__ . "/../../data/proyectos/" . $proyectoSlugLegacy . "/cronicas_img/" . $file;
    $webLegacy = "../../data/proyectos/" . rawurlencode($proyectoSlugLegacy) . "/cronicas_img/" . rawurlencode($file);

    $fsGlobal  = __DIR__ . "/../../data/cronicas_img/" . $file;
    $webGlobal = "../../data/cronicas_img/" . rawurlencode($file);

    if (is_file($fsById))   return $webById;
    if (is_file($fsLegacy)) return $webLegacy;
    if (is_file($fsGlobal)) return $webGlobal;

    // default
    return $webById;
}

include "../../includes/header.php";
?>

<div class="container py-4">
  <h3 class="fw-bold text-primary mb-3">
    <i class="bi bi-pencil"></i> Editar Crónica
  </h3>

  <form method="POST" enctype="multipart/form-data" id="formEditar" novalidate>
    <input type="hidden" name="accion" value="editar">

    <div class="card shadow-sm">
      <div class="card-body">
        <div class="row g-3">

          <div class="col-md-6">
            <label class="form-label">Proyecto</label>
            <input type="text" class="form-control" value="<?= e($nombreProyectoActual) ?>" readonly>
          </div>

          <div class="col-md-6">
            <label class="form-label">Encargado</label>
            <select name="encargado_id" class="form-select select2">
              <?php if ($encargados): foreach($encargados as $en): ?>
                <option value="<?= (int)$en['id'] ?>" <?= ((int)$en['id'] === (int)($cronica['encargado'] ?? 0)) ? 'selected' : '' ?>>
                  <?= e($en['nombre'] ?? '') ?>
                </option>
              <?php endforeach; endif; ?>
            </select>
          </div>

          <div class="col-md-6">
            <label class="form-label">Distrito</label>
            <select name="distrito_id" class="form-select select2">
              <?php if ($distritos): foreach($distritos as $d): ?>
                <option value="<?= (int)$d['id'] ?>" <?= ((int)$d['id'] === (int)($cronica['distrito'] ?? 0)) ? 'selected' : '' ?>>
                  <?= e($d['nombre'] ?? '') ?>
                </option>
              <?php endforeach; endif; ?>
            </select>
          </div>

          <div class="col-md-6">
            <label class="form-label">Tipos de crónica</label>
            <select name="tipo_id[]" class="form-select select2" multiple required>
              <?php foreach($listaTipos as $t): ?>
                <?php $tid = (int)($t['id'] ?? 0); ?>
                <option value="<?= $tid ?>" <?= in_array($tid, array_map('intval', $tiposActual), true) ? 'selected' : '' ?>>
                  <?= e($t['nombre'] ?? '') ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="col-md-4">
            <label class="form-label">Estado</label>
            <select name="estado" class="form-select select2">
              <?php
                $estados = ["Pendiente","En ejecución","Finalizado"];
                foreach($estados as $es):
              ?>
                <option value="<?= e($es) ?>" <?= (($cronica["estado"] ?? '') === $es) ? 'selected' : '' ?>>
                  <?= e($es) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="col-12">
            <label class="form-label">Comentarios</label>
            <textarea name="comentarios" id="editorComentarios" class="form-control" required><?= e($cronica["comentarios"] ?? "") ?></textarea>
          </div>

          <!-- Imágenes -->
          <div class="col-md-4">
            <label class="form-label">Agregar nuevas imágenes</label>
            <input type="file" name="imagenes[]" multiple
                   accept=".jpg,.jpeg,.png,.gif,.webp" class="form-control">

            <?php
              $imgsRaw  = json_decode($cronica["imagenes"] ?? "[]", true);
              $imgsNorm = normalizarImagenes(is_array($imgsRaw) ? $imgsRaw : []);
            ?>
            <?php if (!empty($imgsNorm)): ?>
              <div class="mt-2 d-flex flex-wrap gap-2">
                <?php foreach($imgsNorm as $im):
                    $file = (string)($im['file'] ?? '');
                    if ($file === '') continue;

                    $src = resolverThumbImagen($proyecto_id, $proyectoSlugLegacy, $file);

                    $desc = trim((string)($im['desc'] ?? ''));
                    $title = $desc !== '' ? $desc : $file;
                ?>
                  <div style="width: 110px;">
                    <img src="<?= e($src) ?>" alt="" width="110" height="80"
                         title="<?= e($title) ?>"
                         style="object-fit:cover;border-radius:6px;border:1px solid #ddd;">
                    <div class="small text-muted mt-1" style="line-height:1.1; max-height: 2.4em; overflow:hidden;">
                      <?= $desc !== '' ? e($desc) : 'Sin descripción' ?>
                    </div>
                  </div>
                <?php endforeach; ?>
              </div>
            <?php endif; ?>
          </div>

          <!-- Documentos -->
          <div class="col-md-4">
            <label class="form-label">Agregar nuevos documentos</label>
            <input type="file" name="documentos[]" multiple
                   accept=".pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx" class="form-control">

            <?php
              $docs = json_decode($cronica["documentos"] ?? "[]", true);
              $docs = normalizarListaStrings($docs);
            ?>
            <?php if (!empty($docs)): ?>
              <ul class="mt-2 list-unstyled small">
                <?php foreach($docs as $d): ?>
                  <li><i class="bi bi-file-earmark-text"></i> <?= e($d) ?></li>
                <?php endforeach; ?>
              </ul>
            <?php endif; ?>
          </div>

          <!-- Firmados -->
          <div class="col-md-4">
            <label class="form-label">FIRMADOS ÚNICAMENTE (solo PDF)</label>
            <input type="file" name="firmados[]" multiple accept=".pdf" class="form-control">

            <?php
              $firmados = json_decode($cronica["firmados"] ?? "[]", true);
              $firmados = normalizarListaStrings($firmados);
            ?>
            <?php if (!empty($firmados)): ?>
              <ul class="mt-2 list-unstyled small">
                <?php foreach($firmados as $f): ?>
                  <li>
                    <i class="bi bi-file-earmark-pdf text-danger"></i>
                    <a href="<?= e($baseFirmadosWeb . rawurlencode((string)$f)) ?>" target="_blank" rel="noopener">
                      <?= e($f) ?>
                    </a>
                  </li>
                <?php endforeach; ?>
              </ul>
            <?php endif; ?>
          </div>

        </div>
      </div>

      <div class="card-footer text-end">
        <a href="cronicas.php" class="btn btn-secondary">Cancelar</a>
        <button type="submit" class="btn btn-success">
          <i class="bi bi-save"></i> Guardar cambios
        </button>
      </div>
    </div>
  </form>
</div>

<link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
<script src="https://cdn.jsdelivr.net/npm/jquery@3.7.1/dist/jquery.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
<script src="https://cdn.ckeditor.com/ckeditor5/41.2.1/classic/ckeditor.js"></script>

<style>
.ck-editor__editable { min-height: 150px !important; font-size: 17px; }
.select2-container { width: 100% !important; }
</style>

<script>
let editor;
$(function(){
  $('.select2').select2();
  ClassicEditor.create(document.querySelector('#editorComentarios')).then(e => editor = e);

  $('#formEditar').on('submit', function(e){
    if (editor) this.comentarios.value = editor.getData().trim();
    if (!this.checkValidity()) {
      e.preventDefault();
      e.stopPropagation();
    }
    this.classList.add('was-validated');
  });
});
</script>

<?php include "../../includes/footer.php"; ?>
