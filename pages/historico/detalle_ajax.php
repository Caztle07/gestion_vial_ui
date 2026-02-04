<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);
require_once "../../config/db.php";

header('Content-Type: text/html; charset=utf-8');
mysqli_set_charset($conn, "utf8");

$tipo = $_GET['tipo'] ?? '';
$id   = intval($_GET['id'] ?? 0);

if ($id <= 0) {
    echo "<div class='alert alert-danger'>ID inválido.</div>";
    exit;
}

if ($tipo === 'cronica') {
    $sql = "
        SELECT c.*, 
               p.nombre AS proyecto_nombre, 
               e.nombre AS encargado_nombre, 
               d.nombre AS distrito_nombre
        FROM cronicas c
        INNER JOIN proyectos p 
            ON p.id = c.proyecto_id
            AND p.estado != '0'             -- proyecto activo
        LEFT JOIN encargados e ON e.id = c.encargado
        LEFT JOIN distritos d ON d.id = c.distrito
        WHERE c.id = $id
          AND c.estado_registro != '0'      -- crónica activa
        LIMIT 1
    ";
    $r = $conn->query($sql);
    if (!$r || $r->num_rows === 0) {
        echo "<div class='alert alert-warning'>No se encontró la crónica.</div>";
        exit;
    }
    $c = $r->fetch_assoc();

    echo "
    <div class='container'>
      <h5 class='fw-bold text-success mb-3'><i class='bi bi-journal-text'></i> Crónica #{$c['id']}</h5>
      <p><strong>Proyecto:</strong> " . htmlspecialchars($c['proyecto_nombre'] ?? '-') . "</p>
      <p><strong>Encargado:</strong> " . htmlspecialchars($c['encargado_nombre'] ?? '-') . "</p>
      <p><strong>Distrito:</strong> " . htmlspecialchars($c['distrito_nombre'] ?? '-') . "</p>
      <p><strong>Fecha:</strong> " . (!empty($c['fecha']) ? htmlspecialchars(date('d/m/Y', strtotime($c['fecha']))) : '-') . "</p>
      <hr>
      <p><strong>Descripción:</strong></p>
      <div class='border rounded p-3 bg-light mb-3'>" . nl2br(htmlspecialchars($c['descripcion'] ?? 'Sin descripción.')) . "</div>
    ";

    // Imágenes
    $imgDir = "../../data/cronicas_img/{$c['id']}/";
    if (is_dir($imgDir)) {
        $imgs = glob($imgDir . "*.{jpg,jpeg,png,gif}", GLOB_BRACE);
        if ($imgs && count($imgs) > 0) {
            echo "<h6 class='fw-bold text-primary'><i class='bi bi-images'></i> Imágenes adjuntas:</h6><div class='row'>";
            foreach ($imgs as $img) {
                $path = str_replace("../../", "../", $img);
                echo "
                    <div class='col-md-3 mb-3'>
                      <a href='{$path}' data-lightbox='galeria{$c['id']}' data-title='Imagen'>
                        <img src='{$path}' class='img-fluid rounded shadow-sm'>
                      </a>
                    </div>
                ";
            }
            echo "</div>";
        }
    }

    // Documentos
    $docDir = "../../data/cronicas_docs/{$c['id']}/";
    if (is_dir($docDir)) {
        $docs = glob($docDir . "*.{pdf,docx,txt}", GLOB_BRACE);
        if ($docs && count($docs) > 0) {
            echo "<h6 class='fw-bold text-secondary mt-3'><i class='bi bi-paperclip'></i> Documentos:</h6><ul>";
            foreach ($docs as $d) {
                $name = basename($d);
                $path = str_replace("../../", "../", $d);
                echo "<li><a href='{$path}' target='_blank' class='text-decoration-none'><i class='bi bi-file-earmark'></i> {$name}</a></li>";
            }
            echo "</ul>";
        }
    }

    echo "</div>";
}
elseif ($tipo === 'proyecto') {
    $sql = "
        SELECT p.*, e.nombre AS encargado_nombre, d.nombre AS distrito_nombre
        FROM proyectos p
        LEFT JOIN encargados e ON e.id = p.encargado_id
        LEFT JOIN distritos d ON d.id = p.distrito_id
        WHERE p.id = $id
        LIMIT 1
    ";
    $r = $conn->query($sql);
    if (!$r || $r->num_rows === 0) {
        echo "<div class='alert alert-warning'>No se encontró el proyecto.</div>";
        exit;
    }
    $p = $r->fetch_assoc();

    echo "
    <div class='container'>
      <h5 class='fw-bold text-primary mb-3'><i class='bi bi-diagram-3'></i> Proyecto #{$p['id']}</h5>
      <p><strong>Nombre:</strong> " . htmlspecialchars($p['nombre']) . "</p>
      <p><strong>Encargado:</strong> " . htmlspecialchars($p['encargado_nombre'] ?? '-') . "</p>
      <p><strong>Distrito:</strong> " . htmlspecialchars($p['distrito_nombre'] ?? '-') . "</p>
      <p><strong>Estado:</strong> " . htmlspecialchars($p['estado'] ?? '-') . "</p>
      <hr>
      <p><strong>Descripción:</strong></p>
      <div class='border rounded p-3 bg-light'>" . nl2br(htmlspecialchars($p['descripcion'] ?? 'Sin descripción.')) . "</div>
    </div>";
}
else {
    echo "<div class='alert alert-danger'>Tipo no reconocido.</div>";
}
?>
