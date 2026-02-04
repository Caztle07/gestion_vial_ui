<?php
// pages/proyectos/proyecto_pdf.php

// Modo debug: abre ?debug=1 para ver errores en HTML (no genera PDF)
$DEBUG = isset($_GET['debug']) && $_GET['debug'] == '1';

if ($DEBUG) {
    ini_set('display_errors', 1);
    error_reporting(E_ALL);
} else {
    ini_set('display_errors', 0);
    error_reporting(E_ALL);
    ob_start(); // evita salida accidental antes del PDF
}

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

require_once "../../config/db.php";
require_once "../../auth.php";

require_login();

// Solo admin exporta
if (($_SESSION["rol"] ?? "") !== "admin") {
    http_response_code(403);
    exit("Sin permisos.");
}

$id = isset($_GET["id"]) ? (int)$_GET["id"] : 0;
if ($id <= 0) {
    http_response_code(400);
    exit("ID inválido.");
}

// Helpers DB para detectar columnas/tablas
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
function column_exists(mysqli $conn, string $table, string $col): bool {
    $db = db_name($conn);
    if ($db === '') return false;
    $stmt = $conn->prepare("SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=? AND TABLE_NAME=? AND COLUMN_NAME=? LIMIT 1");
    $stmt->bind_param("sss", $db, $table, $col);
    $stmt->execute();
    $ok = (bool)$stmt->get_result()->fetch_row();
    $stmt->close();
    return $ok;
}

// Texto FPDF (sin utf8_decode)
function pdf_text($s): string {
    $s = (string)$s;
    $out = @iconv('UTF-8', 'Windows-1252//TRANSLIT', $s);
    if ($out === false) $out = $s;
    return $out;
}
function money_crc($n): string {
    return "₡ " . number_format((float)$n, 2, ",", ".");
}
function estado_txt($v): string {
    $v = (string)$v;
    if ($v === "1") return "Pendiente";
    if ($v === "2") return "En ejecución";
    if ($v === "3") return "Finalizado";
    if ($v === "0") return "Papelera";
    return ($v !== "" ? $v : "Sin estado");
}

// 1) Proyecto
$stmt = $conn->prepare("
    SELECT p.id,
           p.nombre AS titulo,
           p.descripcion,
           p.estado,
           p.avance,
           p.fecha_inicio,
           p.fecha_fin,
           p.monto_invertido,
           i.codigo,
           i.nombre AS camino,
           e.nombre AS encargado,
           m.nombre AS modalidad,
           d.nombre AS distrito
    FROM proyectos p
    LEFT JOIN caminos i     ON i.id = p.inventario_id
    LEFT JOIN encargados e  ON e.id = p.encargado_id
    LEFT JOIN modalidades m ON m.id = p.modalidad_id
    LEFT JOIN distritos d   ON d.id = p.distrito_id
    WHERE p.id = ? AND (p.activo IS NULL OR p.activo = 1)
    LIMIT 1
");
$stmt->bind_param("i", $id);
$stmt->execute();
$proy = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$proy) {
    http_response_code(404);
    exit("Proyecto no encontrado o desactivado.");
}

// 2) Tipos (compatibilidad: proyecto_tipos + tareas_catalogo o tipos_cronica)
$tiposTxt = "Sin tipos asociados";
$tipos = [];

if (table_exists($conn, "proyecto_tipos") && column_exists($conn, "proyecto_tipos", "proyecto_id") && column_exists($conn, "proyecto_tipos", "tipo_id")) {

    if (table_exists($conn, "tareas_catalogo")) {
        $stmtT = $conn->prepare("
            SELECT tc.nombre
            FROM proyecto_tipos pt
            INNER JOIN tareas_catalogo tc ON tc.id = pt.tipo_id
            WHERE pt.proyecto_id = ?
            ORDER BY tc.nombre
        ");
        $stmtT->bind_param("i", $id);
        $stmtT->execute();
        $rt = $stmtT->get_result();
        while ($rt && ($row = $rt->fetch_assoc())) $tipos[] = $row["nombre"];
        $stmtT->close();
    } elseif (table_exists($conn, "tipos_cronica")) {
        $stmtT = $conn->prepare("
            SELECT tc.nombre
            FROM proyecto_tipos pt
            INNER JOIN tipos_cronica tc ON tc.id = pt.tipo_id
            WHERE pt.proyecto_id = ?
            ORDER BY tc.nombre
        ");
        $stmtT->bind_param("i", $id);
        $stmtT->execute();
        $rt = $stmtT->get_result();
        while ($rt && ($row = $rt->fetch_assoc())) $tipos[] = $row["nombre"];
        $stmtT->close();
    }

    if (!empty($tipos)) $tiposTxt = implode(", ", $tipos);
}

// 3) Historial montos
$hist = [];
if (table_exists($conn, "proyectos_montos")) {
    $stmtH = $conn->prepare("
        SELECT fecha, monto, nota, creado_en
        FROM proyectos_montos
        WHERE proyecto_id = ?
        ORDER BY fecha DESC, id DESC
    ");
    $stmtH->bind_param("i", $id);
    $stmtH->execute();
    $rh = $stmtH->get_result();
    while ($rh && ($row = $rh->fetch_assoc())) $hist[] = $row;
    $stmtH->close();
}

// 4) Crónicas del proyecto (detecta nombres de columnas)
$cronicas = [];
if (table_exists($conn, "cronicas") && column_exists($conn, "cronicas", "proyecto_id")) {

    // Campo de detalle más común existente
    $detailField = null;
    foreach (["descripcion", "detalle", "observaciones", "comentario", "nota"] as $f) {
        if (column_exists($conn, "cronicas", $f)) { $detailField = $f; break; }
    }
    if ($detailField === null) $detailField = "id"; // fallback para no romper

    // Encargado / distrito en crónicas (puede ser "encargado" o "encargado_id", etc.)
    $colEnc = column_exists($conn, "cronicas", "encargado") ? "encargado" : (column_exists($conn, "cronicas", "encargado_id") ? "encargado_id" : null);
    $colDis = column_exists($conn, "cronicas", "distrito") ? "distrito" : (column_exists($conn, "cronicas", "distrito_id") ? "distrito_id" : null);

    $joinEnc = ($colEnc && table_exists($conn, "encargados")) ? "LEFT JOIN encargados e ON e.id = c.$colEnc" : "";
    $joinDis = ($colDis && table_exists($conn, "distritos"))  ? "LEFT JOIN distritos  d ON d.id = c.$colDis"  : "";

    // Campos base que casi siempre existen
    $selConsec = column_exists($conn, "cronicas", "consecutivo") ? "c.consecutivo" : "c.id AS consecutivo";
    $selFecha  = column_exists($conn, "cronicas", "fecha") ? "c.fecha" : "NULL AS fecha";
    $selEstado = column_exists($conn, "cronicas", "estado") ? "c.estado" : "'' AS estado";

    $sqlC = "
        SELECT c.id,
               $selConsec,
               $selFecha,
               $selEstado,
               c.$detailField AS detalle,
               " . ($joinEnc ? "e.nombre AS encargado_nombre," : "NULL AS encargado_nombre,") . "
               " . ($joinDis ? "d.nombre AS distrito_nombre" : "NULL AS distrito_nombre") . "
        FROM cronicas c
        $joinEnc
        $joinDis
        WHERE c.proyecto_id = ?
    ";

    // Filtro papelera si existe estado_registro
    if (column_exists($conn, "cronicas", "estado_registro")) {
        $sqlC .= " AND (c.estado_registro IS NULL OR (c.estado_registro <> '0' AND c.estado_registro <> 'papelera')) ";
    }

    // Orden
    if (column_exists($conn, "cronicas", "fecha")) {
        $sqlC .= " ORDER BY c.fecha DESC, c.id DESC ";
    } else {
        $sqlC .= " ORDER BY c.id DESC ";
    }

    $stmtC = $conn->prepare($sqlC);
    $stmtC->bind_param("i", $id);
    $stmtC->execute();
    $rc = $stmtC->get_result();
    while ($rc && ($row = $rc->fetch_assoc())) $cronicas[] = $row;
    $stmtC->close();
}

if ($DEBUG) {
    header("Content-Type: text/plain; charset=utf-8");
    echo "DEBUG proyecto_pdf.php\n\n";
    echo "Proyecto ID: $id\n";
    echo "DB: " . db_name($conn) . "\n\n";
    echo "Tipos: $tiposTxt\n";
    echo "Historial montos: " . count($hist) . " filas\n";
    echo "Crónicas: " . count($cronicas) . " filas\n\n";
    echo "Si aún hay 500, revisá el log de Apache/PHP.\n";
    exit;
}

// FPDF (después de consultas)
require_once "../../libs/fpdf/fpdf.php";

class PDF extends FPDF {
    public $tituloDoc = "";

    function Header() {
        $this->SetFont('Arial', 'B', 13);
        $this->Cell(0, 8, pdf_text("Gestión Vial - Reporte de Proyecto"), 0, 1, 'L');
        $this->SetFont('Arial', '', 10);
        $this->Cell(0, 6, pdf_text($this->tituloDoc), 0, 1, 'L');
        $this->Ln(2);
        $this->Line(10, $this->GetY(), 200, $this->GetY());
        $this->Ln(6);
    }

    function Footer() {
        $this->SetY(-14);
        $this->SetFont('Arial', '', 9);
        $this->Cell(0, 8, pdf_text("Página " . $this->PageNo()), 0, 0, 'C');
    }

    function sectionTitle($txt) {
        $this->SetFont('Arial', 'B', 11);
        $this->Cell(0, 7, pdf_text($txt), 0, 1, 'L');
        $this->Ln(1);
    }

    function kv($k, $v) {
        $this->SetFont('Arial', 'B', 10);
        $this->Cell(45, 6, pdf_text($k), 0, 0, 'L');
        $this->SetFont('Arial', '', 10);
        $this->MultiCell(0, 6, pdf_text($v), 0, 'L');
    }

    function tableHeader($cols) {
        $this->SetFont('Arial', 'B', 9);
        foreach ($cols as $c) {
            $this->Cell($c[1], 7, pdf_text($c[0]), 1, 0, 'C');
        }
        $this->Ln();
    }

    function tableRow($cols, $values) {
        $this->SetFont('Arial', '', 9);

        $startX = $this->GetX();
        $startY = $this->GetY();

        $heights = [];
        for ($i=0; $i<count($cols); $i++) {
            $w = $cols[$i][1];
            $txt = pdf_text($values[$i] ?? "");
            $lines = max(1, (int)ceil($this->GetStringWidth($txt) / max(1, ($w - 2))));
            $heights[] = 5 * $lines;
        }
        $h = max($heights);

        for ($i=0; $i<count($cols); $i++) {
            $w = $cols[$i][1];
            $txt = pdf_text($values[$i] ?? "");
            $x = $this->GetX();
            $y = $this->GetY();

            $this->Rect($x, $y, $w, $h);
            $this->MultiCell($w, 5, $txt, 0, 'L');
            $this->SetXY($x + $w, $y);
        }
        $this->Ln($h);
    }
}

$pdf = new PDF('P', 'mm', 'A4');
$pdf->tituloDoc = "Proyecto #{$proy['id']} - " . ($proy["titulo"] ?? "");
$pdf->SetAutoPageBreak(true, 18);
$pdf->AddPage();

$pdf->sectionTitle("Datos del proyecto");
$pdf->kv("Nombre:", (string)($proy["titulo"] ?? ""));
$pdf->kv("Camino:", trim(($proy["codigo"] ?? "") . " - " . ($proy["camino"] ?? "")));
$pdf->kv("Encargado:", (string)($proy["encargado"] ?? "-"));
$pdf->kv("Modalidad:", (string)($proy["modalidad"] ?? "-"));
$pdf->kv("Distrito:", (string)($proy["distrito"] ?? "-"));
$pdf->kv("Tipos:", (string)$tiposTxt);
$pdf->kv("Fecha inicio:", (string)($proy["fecha_inicio"] ?? "-"));
$pdf->kv("Fecha fin:", (string)($proy["fecha_fin"] ?? "-"));
$pdf->kv("Estado:", estado_txt($proy["estado"] ?? ""));
$pdf->kv("Avance:", ((int)($proy["avance"] ?? 0)) . "%");
$pdf->kv("Monto invertido:", money_crc($proy["monto_invertido"] ?? 0));

$pdf->Ln(3);
$pdf->sectionTitle("Descripción");
$descPlain = trim(strip_tags((string)($proy["descripcion"] ?? "")));
$pdf->SetFont('Arial', '', 10);
$pdf->MultiCell(0, 6, pdf_text($descPlain !== "" ? $descPlain : "Sin descripción registrada."), 0, 'L');

$pdf->Ln(4);
$pdf->sectionTitle("Historial de montos");
if (empty($hist)) {
    $pdf->SetFont('Arial', '', 10);
    $pdf->MultiCell(0, 6, pdf_text("No hay montos registrados para este proyecto."), 0, 'L');
} else {
    $cols = [
        ["Fecha", 22],
        ["Monto", 28],
        ["Nota", 90],
        ["Registrado en", 50],
    ];
    $pdf->tableHeader($cols);
    foreach ($hist as $h) {
        $pdf->tableRow($cols, [
            (string)($h["fecha"] ?? ""),
            money_crc($h["monto"] ?? 0),
            (string)($h["nota"] ?? ""),
            (string)($h["creado_en"] ?? ""),
        ]);
    }
}

$pdf->Ln(4);
$pdf->sectionTitle("Crónicas del proyecto");
if (empty($cronicas)) {
    $pdf->SetFont('Arial', '', 10);
    $pdf->MultiCell(0, 6, pdf_text("No hay crónicas activas asociadas a este proyecto."), 0, 'L');
} else {
    $cols = [
        ["Consec.", 22],
        ["Fecha", 22],
        ["Estado", 25],
        ["Encargado", 45],
        ["Distrito", 35],
        ["Detalle", 41],
    ];
    $pdf->tableHeader($cols);

    foreach ($cronicas as $c) {
        $fecha = "";
        if (!empty($c["fecha"])) {
            $ts = strtotime($c["fecha"]);
            $fecha = $ts ? date("d/m/Y", $ts) : (string)$c["fecha"];
        }

        $pdf->tableRow($cols, [
            (string)($c["consecutivo"] ?? ("ID " . ($c["id"] ?? ""))),
            $fecha,
            (string)($c["estado"] ?? ""),
            (string)($c["encargado_nombre"] ?? "-"),
            (string)($c["distrito_nombre"] ?? "-"),
            (string)($c["detalle"] ?? ""),
        ]);
    }
}

// Limpia cualquier salida accidental
ob_end_clean();

$filename = "proyecto_{$id}_" . date("Ymd_His") . ".pdf";
$pdf->Output("I", $filename);
exit;
