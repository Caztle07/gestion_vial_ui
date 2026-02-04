<?php
require_once "../../config/db.php";
require_once "../../auth.php";

$id = intval($_GET["id"] ?? 0);
if($id <= 0){ die("Sin ID"); }

mysqli_set_charset($conn,"utf8");

$c = $conn->query("
SELECT c.*,
       p.nombre AS proyecto_nombre,
       p.modalidad_id,
       d.nombre AS distrito_nombre,
       m.nombre AS modalidad_nombre,
       cam.codigo AS camino_codigo,
       cam.desde AS camino_desde,
       cam.hasta AS camino_hasta
FROM cronicas c
LEFT JOIN proyectos p ON p.id = c.proyecto_id
LEFT JOIN distritos d ON d.id = c.distrito
LEFT JOIN modalidades m ON m.id = p.modalidad_id
LEFT JOIN caminos cam ON cam.id = p.inventario_id
WHERE c.id = $id
")->fetch_assoc();

if(!$c){ die("Crónica no encontrada"); }

$presente  = $_SESSION["nombre"] ?? "";
$ins = "";

if($c["encargado"]){
  $r = $conn->query("SELECT nombre FROM encargados WHERE id=".$c["encargado"])->fetch_assoc();
  if($r){ $ins = $r["nombre"]; }
}

$provincia = "Puntarenas";
$canton    = "Brunka";
$fecha     = date("d/m/Y", strtotime($c["fecha"]));

// ===============================
// IMÁGENES ADJUNTAS (JSON)
// ===============================
$imgs = [];
if (!empty($c["imagenes"])) {
    $tmp = json_decode($c["imagenes"], true);
    if (is_array($tmp)) $imgs = $tmp;
}

// ===============================
// DOCUMENTOS ADJUNTOS (JSON)
// ===============================
$docs = [];
if (!empty($c["documentos"])) {
    $tmpD = json_decode($c["documentos"], true);
    if (is_array($tmpD)) $docs = $tmpD;
}

// ===============================
// FIRMADOS (JSON)
// ===============================
$firmados = [];
if (!empty($c["firmados"])) {
    $tmpF = json_decode($c["firmados"], true);
    if (is_array($tmpF)) $firmados = $tmpF;
}

// slug del proyecto para carpeta por proyecto
$proyectoSlug = preg_replace('/[^A-Za-z0-9_\-]/', '_', $c["proyecto_nombre"] ?? "");

// rutas base web
$baseFirmadosWeb = "../../data/proyectos/" . $proyectoSlug . "/cronicas_firmadas/";
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="utf-8">
<title>Crónica</title>
<style>
@page{
    size: letter;
    margin:0;
}
*{
    box-sizing:border-box;
}
body{
    margin:0;
    padding:0;
    font-family:Arial, Helvetica, sans-serif;
    font-size:13px;
    background:#f5f5f5;
    -webkit-print-color-adjust: exact;
    print-color-adjust: exact;
}

/* Cada "page" es una hoja física */
.page{
    width:190mm;
    min-height:250mm;
    margin:5mm auto;
    padding:12mm 14mm;
    background:#ffffff;
    border:1px solid #000;
    position:relative;
    page-break-after: always;
}
.page:last-of-type{
    page-break-after: auto;
}

/* Encabezado con 2 logos */
.header-print{
    display:flex;
    justify-content:space-between;
    align-items:center;
}
.header-logo-left img,
.header-logo-right img{
    max-width:80px;
    max-height:80px;
}
.header-center{
    text-align:center;
    font-size:12px;
}

/* Título */
.titulo-cronica{
    text-align:center;
    margin:10px 0 14px;
    font-size:15px;
    font-weight:bold;
    text-transform:uppercase;
}

/* Bloques texto */
.bloque{
    margin-bottom:10px;
    font-size:13px;
}
.bloque-linea{
    margin-bottom:4px;
}

/* OBSERVACIONES */
.bloque-observaciones{
    width:100%;
    min-height:60mm;
    margin-top:4px;
    padding:8px;
    border:1px solid #000;
    white-space:pre-wrap;
    box-sizing:border-box;
}

/* Listas */
.lista-docs li,
.lista-firmados li{
    font-size:12px;
    margin-bottom:2px;
}

/* GALERIA */
.galeria-img{
    margin-top:12px;
    display:flex;
    flex-wrap:wrap;
    gap:8px;
}
.galeria-img figure{
    border:1px solid #000;
    padding:4px;
    width:60mm;
    text-align:center;
    font-size:11px;
}
.galeria-img img{
    max-width:100%;
    max-height:45mm;
    object-fit:contain;
}

/* FIRMAS */
.firmas{
    margin-top:18mm;
    display:flex;
    justify-content:space-between;
}
.linea-firma{
    width:100%;
    border-bottom:1px solid #000;
    padding-top:30px;
    margin-bottom:4px;
}

/* FOOTER */
.footer-print{
    position:absolute;
    bottom:4mm;
    left:50%;
    transform:translateX(-50%);
    font-size:11px;
    font-weight:bold;
    text-align:center;
}

.btn-imprimir{
    margin:10px auto;
    padding:6px 12px;
    background:#28a745;
    color:#fff;
    border:none;
    border-radius:4px;
    cursor:pointer;
}
@media print{
    .btn-imprimir{ display:none; }
}
</style>
</head>
<body>

<!-- BOTÓN IMPRIMIR -->
<div style="text-align:center; margin-top:8px;">
    <button class="btn-imprimir" onclick="window.print()">🖨 Imprimir Crónica</button>
</div>

<!-- ==========================
     HOJA 1
     ========================== -->
<div class="page">

    <!-- ENCABEZADO (LOGO IZQUIERDA + LOGO DERECHA) -->
    <div class="header-print">
        <div class="header-logo-left">
            <img src="../../logo2.png" alt="Logo Municipalidad">
        </div>

        <div class="header-center"></div>

        <div class="header-logo-right">
            <img src="../../logo2.png" alt="Logo Municipalidad">
        </div>
    </div>

    <div class="titulo-cronica">
        INSPECCIÓN DE OBRA EN CONSTRUCCIÓN #<?= $c["id"] ?>-2025
    </div>

    <!-- DATOS -->
    <div class="bloque">
        <div class="bloque-linea"><strong>Provincia:</strong> <?= $provincia ?> &nbsp; <strong>Cantón:</strong> <?= $canton ?></div>
        <div class="bloque-linea"><strong>Distrito:</strong> <?= $c["distrito_nombre"] ?></div>
        <div class="bloque-linea"><strong>Fecha:</strong> <?= $fecha ?></div>
        <div class="bloque-linea"><strong>Código:</strong> <?= $c["camino_codigo"] ?></div>
        <div class="bloque-linea"><strong>Desde:</strong> <?= $c["camino_desde"] ?> &nbsp; <strong>Hasta:</strong> <?= $c["camino_hasta"] ?></div>
        <div class="bloque-linea"><strong>Modalidad:</strong> <?= $c["modalidad_nombre"] ?></div>
        <div class="bloque-linea"><strong>Inspección en:</strong> Camino</div>
        <div class="bloque-linea"><strong>Presente:</strong> <?= htmlspecialchars($presente) ?></div>
    </div>

    <!-- OBSERVACIONES -->
    <div class="bloque">
        <strong>Comentarios:</strong>
        <div class="bloque-observaciones">
            <?= nl2br(htmlspecialchars(strip_tags($c["comentarios"]))) ?>
        </div>
    </div>

    <!-- DOCUMENTOS -->
    <?php if (!empty($docs)): ?>
    <div class="bloque">
        <strong>Documentos adjuntos:</strong>
        <ul class="lista-docs">
            <?php foreach ($docs as $d): ?>
                <?php
                $docNameSafe = htmlspecialchars($d);
                $ruta1 = "../../data/proyectos/$proyectoSlug/cronicas_docs/$docNameSafe";
                ?>
                <li><a href="<?= $ruta1 ?>" target="_blank"><?= $docNameSafe ?></a></li>
            <?php endforeach; ?>
        </ul>
    </div>
    <?php endif; ?>

    <!-- FIRMADOS -->
    <?php if (!empty($firmados)): ?>
    <div class="bloque">
        <strong>Documentos firmados:</strong>
        <ul class="lista-firmados">
            <?php foreach ($firmados as $f): ?>
                <li><a href="<?= $baseFirmadosWeb . htmlspecialchars($f) ?>" target="_blank"><?= htmlspecialchars($f) ?></a></li>
            <?php endforeach; ?>
        </ul>
    </div>
    <?php endif; ?>

    <!-- FIRMAS -->
    <div class="firmas">
        <div class="firma">
            <div class="linea-firma"></div>
            <?= htmlspecialchars($ins) ?><br>Inspector
        </div>

        <div class="firma">
            <div class="linea-firma"></div>
            Administrador General<br>Ingeniero Encargado
        </div>
    </div>

    <div class="footer-print">
        MUNICIPALIDAD DE BUENOS AIRES
    </div>

</div>

<!-- ==========================
     HOJA 2 — IMÁGENES
     ========================== -->
<?php if (!empty($imgs)): ?>
<div class="page">

    <div class="header-print">
        <div class="header-logo-left">
            <img src="../../logo2.png" alt="Logo Municipalidad">
        </div>

        <div class="header-center"></div>

        <div class="header-logo-right">
            <img src="../../logo2.png" alt="Logo Municipalidad">
        </div>
    </div>

    <div class="bloque" style="margin-top:10px;">
        <strong>Imágenes adjuntas:</strong>
        <div class="galeria-img">
        <?php foreach ($imgs as $img):
            $safe = htmlspecialchars($img);
            $src = "../../data/proyectos/$proyectoSlug/cronicas_img/$safe";
        ?>
            <figure>
                <img src="<?= $src ?>" alt="Imagen Crónica">
                <figcaption><?= htmlspecialchars(str_replace(['_','-'],' ', pathinfo($img, PATHINFO_FILENAME))) ?></figcaption>
            </figure>
        <?php endforeach; ?>
        </div>
    </div>

    <div class="footer-print">
        MUNICIPALIDAD DE BUENOS AIRES, TRABAJANDO POR EL CANTÓN QUE TODOS QUEREMOS
    </div>

</div>
<?php endif; ?>

<script>
window.onload = function(){ window.print(); };
</script>

</body>
</html>
