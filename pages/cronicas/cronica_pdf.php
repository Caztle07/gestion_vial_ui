<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once "../../config/db.php";
require_once "../../auth.php";

require_login();

if (!function_exists('can_edit') || !(can_edit("admin") || can_edit("cronicas") || can_edit("cronicas_crear") || can_edit("cronicas_ver"))) {
    http_response_code(403);
    exit("Sin permisos.");
}

mysqli_set_charset($conn, "utf8");

// ================================
// Helpers
// ================================
function pdf_text(string $s): string {
    $s = trim($s);
    if ($s === '') return '';
    $s = html_entity_decode($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $out = @iconv('UTF-8', 'Windows-1252//TRANSLIT', $s);
    return $out !== false ? $out : $s;
}

function html_to_plain(string $html): string {
    if (trim($html) === '') return '';
    $html = preg_replace('/<\s*br\s*\/?>/i', "\n", $html);
    $html = preg_replace('/<\/\s*p\s*>/i', "\n\n", $html);
    $html = preg_replace('/<\/\s*li\s*>/i', "\n", $html);
    $html = strip_tags($html);
    $html = preg_replace("/[ \t]+\n/", "\n", $html);
    $html = preg_replace("/\n{3,}/", "\n\n", $html);
    return trim($html);
}

function normalizarImagenes($imagenesRaw): array {
    $out = [];
    if (!is_array($imagenesRaw)) return $out;

    foreach ($imagenesRaw as $item) {
        // Nuevo
        if (is_array($item) && isset($item['file'])) {
            $file = trim((string)$item['file']);
            if ($file === '') continue;
            $desc = isset($item['desc']) ? trim((string)$item['desc']) : '';
            $out[] = ['file' => $file, 'desc' => $desc];
            continue;
        }
        // Viejo
        if (is_string($item)) {
            $file = trim($item);
            if ($file === '') continue;
            $out[] = ['file' => $file, 'desc' => ''];
        }
    }
    return $out;
}

function normalizarAdjuntosImagenes($raw): array {
    // adjuntos se guarda como lista simple ["file.jpg", ...]
    $out = [];
    if (!is_array($raw)) return $out;
    foreach ($raw as $it) {
        if (!is_string($it)) $it = (string)$it;
        $it = trim($it);
        if ($it === '') continue;
        $out[] = ['file' => $it, 'desc' => '']; // sin descripción
    }
    return $out;
}

function getDataBasePath(): ?string {
    $rootGuess = __DIR__ . DIRECTORY_SEPARATOR . ".." . DIRECTORY_SEPARATOR . ".."; // .../gestion_vial_ui
    $root = realpath($rootGuess);
    if ($root === false) $root = $rootGuess;

    $data = rtrim($root, "/\\") . DIRECTORY_SEPARATOR . "data";
    return is_dir($data) ? $data : null;
}

function get_img_type_supported(string $path): ?string {
    $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    // FPDF base soporta: jpg/jpeg/png/gif
    if (in_array($ext, ['jpg','jpeg','png','gif'], true)) return $ext;
    return null;
}

/** intenta armar nombre completo del inspector sin asumir columnas exactas */
function build_fullname(array $u): string {
    $full = trim((string)($u['nombre_completo'] ?? $u['nombreCompleto'] ?? $u['full_name'] ?? ''));
    if ($full !== '') return $full;

    $nombre = trim((string)($u['nombre'] ?? $u['name'] ?? ''));
    $ap1    = trim((string)($u['apellido1'] ?? $u['primer_apellido'] ?? $u['apellido'] ?? ''));
    $ap2    = trim((string)($u['apellido2'] ?? $u['segundo_apellido'] ?? ''));
    $ap     = trim((string)($u['apellidos'] ?? $u['last_name'] ?? ''));
    if ($ap === '' && ($ap1 !== '' || $ap2 !== '')) $ap = trim($ap1 . ' ' . $ap2);

    $out = trim($nombre . ' ' . $ap);
    if ($out !== '') return $out;

    $user = trim((string)($u['usuario'] ?? $u['username'] ?? ''));
    if ($user !== '') return $user;

    return 'Inspector';
}

function pick_first(array $u, array $keys, string $default = '—'): string {
    foreach ($keys as $k) {
        if (isset($u[$k]) && trim((string)$u[$k]) !== '') return trim((string)$u[$k]);
    }
    return $default;
}

/** resuelve ruta fisica: primero por carpeta específica, luego fallback global antiguo */
function resolve_image_abs(?string $dir, string $file): ?string {
    $file = trim($file);
    if ($file === '') return null;

    if ($dir) {
        $p = $dir . DIRECTORY_SEPARATOR . $file;
        if (file_exists($p)) return $p;
    }

    // fallback global antiguo (solo para cronicas_img histórico)
    $p2 = realpath(__DIR__ . "/../../data/cronicas_img/" . $file);
    if ($p2 && file_exists($p2)) return $p2;

    return null;
}

// ================================
// Validar ID
// ================================
$id = isset($_GET["id"]) ? (int)$_GET["id"] : 0;
if ($id <= 0) {
    http_response_code(400);
    exit("ID inválido.");
}

// ================================
// Cargar crónica
// ================================
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
    http_response_code(404);
    exit("Crónica no encontrada.");
}

// ================================
// Inspector (usuario que creó la crónica)
// ================================
$usuario = [];
$usuario_id = (int)($cronica['usuario_id'] ?? 0);
if ($usuario_id > 0) {
    $stmtU = $conn->prepare("SELECT * FROM usuarios WHERE id = ? LIMIT 1");
    $stmtU->bind_param("i", $usuario_id);
    $stmtU->execute();
    $usuario = $stmtU->get_result()->fetch_assoc() ?: [];
    $stmtU->close();
}

$inspectorNombre = build_fullname($usuario);
$inspectorCorreo = pick_first($usuario, ['correo','email','mail'], '—');
$inspectorTel    = pick_first($usuario, ['telefono','tel','celular','phone'], '—');
$inspectorExt    = pick_first($usuario, ['extension','ext'], '—');

// Consecutivo mostrado: GV-XXX-AAAA
$consecutivoBase = $cronica['consecutivo'] ?? '';
$anioConsec = !empty($cronica['fecha']) ? date('Y', strtotime($cronica['fecha'])) : date('Y');
$consecutivoMostrar = $consecutivoBase ? ($consecutivoBase . '-' . $anioConsec) : ('CRONICA-' . $id);

// Tipos de crónica (nombres)
$tiposTexto = "-";
$tiposArr = json_decode($cronica["tipo"] ?? "[]", true);
if (!is_array($tiposArr)) $tiposArr = [];

if (!empty($tiposArr)) {
    $ids = implode(",", array_map('intval', $tiposArr));
    $rsTipos = $conn->query("SELECT nombre FROM tipos_cronica WHERE id IN ($ids)");
    $nombres = [];
    if ($rsTipos) {
        while ($r = $rsTipos->fetch_assoc()) $nombres[] = $r["nombre"];
    }
    if (!empty($nombres)) $tiposTexto = implode(", ", $nombres);
}

// Datos para PDF
$provincia = "Puntarenas";
$canton    = "Buenos Aires";

$proyecto   = $cronica['proyecto_nombre'] ?? '-';
$encargado  = $cronica['encargado_nombre'] ?? '-';
$distrito   = $cronica['distrito_nombre'] ?? '-';
$estado     = $cronica['estado'] ?? '-';
$fechaTxt   = !empty($cronica['fecha']) ? date('d/m/Y', strtotime($cronica['fecha'])) : '-';

$comentariosHtml = (string)($cronica["comentarios"] ?? '');
$comentariosTxt  = html_to_plain($comentariosHtml);
if ($comentariosTxt === '') $comentariosTxt = "Sin observaciones.";

// Archivos
$imagenesRaw = json_decode($cronica["imagenes"] ?? "[]", true);
if (!is_array($imagenesRaw)) $imagenesRaw = [];
$imagenesNorm = normalizarImagenes($imagenesRaw);

// Adjuntos de imágenes
$adjuntosRaw = json_decode($cronica["adjuntos"] ?? "[]", true);
if (!is_array($adjuntosRaw)) $adjuntosRaw = [];
$adjuntosNorm = normalizarAdjuntosImagenes($adjuntosRaw);

$documentos = json_decode($cronica["documentos"] ?? "[]", true);
$firmados   = json_decode($cronica["firmados"]   ?? "[]", true);
if (!is_array($documentos)) $documentos = [];
if (!is_array($firmados))   $firmados   = [];

// ================================
// Resolver rutas físicas por proyecto (AHORA por ID)
// ================================
$dataBase = getDataBasePath(); // .../gestion_vial_ui/data
$proyectoIdFS = (int)($cronica['proyecto_id'] ?? 0);

$rutaImgDir = null;
$rutaAdjDir = null;

if ($dataBase && $proyectoIdFS > 0) {
    $baseProyecto = rtrim($dataBase, "/\\")
        . DIRECTORY_SEPARATOR . "proyectos"
        . DIRECTORY_SEPARATOR . $proyectoIdFS;

    $rutaImgDir = $baseProyecto . DIRECTORY_SEPARATOR . "cronicas_img";
    $rutaAdjDir = $baseProyecto . DIRECTORY_SEPARATOR . "cronicas_adjuntos";
}

// ================================
// FPDF
// ================================
require_once "../../libs/fpdf/fpdf.php";

class PDF extends FPDF {
    public string $escudoPath = '';
    public string $contactoLinea = '';
    public string $tituloCronica = '';

    function Header() {
        if ($this->escudoPath && file_exists($this->escudoPath)) {
            $this->Image($this->escudoPath, 10, 8, 18);
        }

        $this->SetY(10);
        $this->SetFont('Arial','B',12);
        $this->Cell(0, 5, pdf_text('MUNICIPALIDAD DE BUENOS AIRES'), 0, 1, 'C');

        $this->SetFont('Arial','',9);
        if (trim($this->contactoLinea) !== '') {
            $this->Cell(0, 4, pdf_text($this->contactoLinea), 0, 1, 'C');
        }

        $this->SetFont('Arial','B',10);
        $this->Cell(0, 5, pdf_text('GESTIÓN VIAL MUNICIPAL'), 0, 1, 'C');

        $this->Ln(2);
        $this->SetDrawColor(0,0,0);
        $this->Line(10, $this->GetY(), 200, $this->GetY());
        $this->Ln(6);

        if ($this->tituloCronica !== '') {
            $this->SetFont('Arial','B',11);
            $this->Cell(0, 6, pdf_text($this->tituloCronica), 0, 1, 'C');
            $this->SetFont('Arial','B',10);
            $this->Cell(0, 5, pdf_text('Inspección de Proyectos'), 0, 1, 'C');
            $this->Ln(2);
        }
    }

    function Footer() {
        $this->SetY(-15);
        $this->SetFont('Arial','',8);
        $this->SetTextColor(90,90,90);
        $this->Cell(0, 10, pdf_text('Página ') . $this->PageNo() . pdf_text(' / {nb}'), 0, 0, 'C');
    }
}

function row2(PDF $pdf, string $l1, string $v1, string $l2, string $v2): void {
    $pdf->SetFont('Arial','B',9);
    $pdf->Cell(22, 6, pdf_text($l1), 0, 0, 'L');
    $pdf->SetFont('Arial','',9);
    $pdf->Cell(73, 6, pdf_text($v1), 0, 0, 'L');

    $pdf->SetFont('Arial','B',9);
    $pdf->Cell(22, 6, pdf_text($l2), 0, 0, 'L');
    $pdf->SetFont('Arial','',9);
    $pdf->Cell(73, 6, pdf_text($v2), 0, 1, 'L');
}

function print_list(PDF $pdf, string $title, array $items): void {
    $pdf->SetFont('Arial','B',10);
    $pdf->Cell(0, 6, pdf_text($title), 0, 1, 'L');
    $pdf->SetFont('Arial','',9);

    if (empty($items)) {
        $pdf->Cell(0, 5, pdf_text('- Sin archivos.'), 0, 1, 'L');
        return;
    }
    foreach ($items as $it) {
        if (!is_string($it)) $it = (string)$it;
        $it = trim($it);
        if ($it === '') continue;
        $pdf->MultiCell(0, 5, pdf_text('- ' . $it), 0, 'L');
    }
}

/** grid 2x3 por página, caption abajo */
function addRegistroFotografico(PDF $pdf, ?string $dir, array $items, string $titleIfNewPage = 'Registro Fotográfico'): void {
    if (empty($items)) {
        $pdf->SetFont('Arial','',10);
        $pdf->Cell(0, 6, pdf_text('No hay imágenes registradas.'), 0, 1, 'L');
        return;
    }

    $marginL = 10;
    $marginR = 10;
    $usableW = 210 - $marginL - $marginR; // 190
    $gapX = 2;
    $cellW = ($usableW - $gapX) / 2; // ~94

    $imgH = 55;
    $capH = 10;
    $cellH = $imgH + $capH; // 65

    $startY = $pdf->GetY();
    $x0 = $marginL;

    $maxPerPage = 6;
    $i = 0;

    foreach ($items as $im) {
        $file = trim((string)($im['file'] ?? ''));
        if ($file === '') continue;

        $desc = trim((string)($im['desc'] ?? ''));
        if ($desc === '') $desc = 'Sin descripción.';

        if ($i > 0 && ($i % $maxPerPage) === 0) {
            $pdf->AddPage();
            $pdf->SetFont('Arial','B',11);
            $pdf->Cell(0, 7, pdf_text($titleIfNewPage), 0, 1, 'C');
            $pdf->Ln(2);
            $startY = $pdf->GetY();
        }

        $pos = $i % $maxPerPage;
        $row = intdiv($pos, 2);
        $col = $pos % 2;

        $x = $x0 + $col * ($cellW + $gapX);
        $y = $startY + $row * ($cellH + 2);

        if ($y + $cellH > ($pdf->GetPageHeight() - 18)) {
            $pdf->AddPage();
            $pdf->SetFont('Arial','B',11);
            $pdf->Cell(0, 7, pdf_text($titleIfNewPage), 0, 1, 'C');
            $pdf->Ln(2);
            $startY = $pdf->GetY();
            $x = $x0;
            $y = $startY;
        }

        $pdf->SetDrawColor(0,0,0);
        $pdf->Rect($x, $y, $cellW, $cellH);
        $pdf->Line($x, $y + $imgH, $x + $cellW, $y + $imgH);

        $abs = resolve_image_abs($dir, $file);
        $supported = ($abs && file_exists($abs)) ? get_img_type_supported($abs) : null;

        if ($supported) {
            $innerW = $cellW - 3;
            $innerH = $imgH - 3;

            $info = @getimagesize($abs);
            if ($info && isset($info[0], $info[1]) && $info[0] > 0 && $info[1] > 0) {
                $iw = (float)$info[0];
                $ih = (float)$info[1];
                $scale = min($innerW / $iw, $innerH / $ih);
                $w = $iw * $scale;
                $h = $ih * $scale;
                $cx = $x + (($cellW - $w) / 2);
                $cy = $y + (($imgH - $h) / 2);
                $pdf->Image($abs, $cx, $cy, $w, $h);
            } else {
                $pdf->Image($abs, $x + 1.5, $y + 1.5, $innerW, 0);
            }
        } else {
            $pdf->SetFont('Arial','',8);
            $pdf->SetXY($x + 2, $y + 2);
            $pdf->MultiCell($cellW - 4, 4, pdf_text('Imagen no disponible o formato no soportado.'), 0, 'L');
        }

        $pdf->SetFont('Arial','',8);
        $pdf->SetXY($x + 2, $y + $imgH + 1.5);
        $pdf->MultiCell($cellW - 4, 4, pdf_text($desc), 0, 'C');

        $i++;
    }

    $pdf->SetY($startY + 3 * ($cellH + 2));
}

// ================================
// PDF build
// ================================
$pdf = new PDF('P','mm','A4');
$pdf->AliasNbPages();
$pdf->SetMargins(10, 10, 10);
$pdf->SetAutoPageBreak(true, 18);

$escudoPath = realpath(__DIR__ . "/../../logo2.png");
$pdf->escudoPath = $escudoPath ? $escudoPath : '';

$contacto = [];
if ($inspectorTel !== '—') $contacto[] = "Teléfono: " . $inspectorTel;
if ($inspectorExt !== '—') $contacto[] = "Ext: " . $inspectorExt;
if ($inspectorCorreo !== '—') $contacto[] = $inspectorCorreo;
$pdf->contactoLinea = implode("   ", $contacto);

$pdf->tituloCronica = "CRÓNICA " . $consecutivoMostrar;

// ================================
// Página 1 (texto)
// ================================
$pdf->AddPage();

row2($pdf, 'Provincia:', $provincia, 'Distrito:', $distrito);
row2($pdf, 'Cantón:',    $canton,    'Fecha:',    $fechaTxt);
row2($pdf, 'Proyecto:',  $proyecto,  'Estado:',   $estado);

$pdf->SetFont('Arial','B',9);
$pdf->Cell(22, 6, pdf_text('Inspección:'), 0, 0, 'L');
$pdf->SetFont('Arial','',9);
$pdf->MultiCell(0, 6, pdf_text($tiposTexto), 0, 'L');

$pdf->SetFont('Arial','B',9);
$pdf->Cell(22, 6, pdf_text('Inspector:'), 0, 0, 'L');
$pdf->SetFont('Arial','',9);
$pdf->MultiCell(0, 6, pdf_text($inspectorNombre), 0, 'L');

$pdf->Ln(2);

$pdf->SetFont('Arial','B',10);
$pdf->Cell(0, 6, pdf_text('Observaciones:'), 0, 1, 'L');

$pdf->SetFont('Arial','',10);
$pdf->MultiCell(0, 5.5, pdf_text($comentariosTxt), 0, 'J');

$pdf->Ln(6);
$pdf->SetFont('Arial','I',9);
$pdf->Cell(0, 5, pdf_text('Cc. Expediente'), 0, 1, 'L');

// ================================
// Firmas (siempre al final de página 1)
// ================================
$yFirmas = $pdf->GetPageHeight() - 45;
if ($pdf->GetY() < $yFirmas) $pdf->SetY($yFirmas);

$pdf->SetFont('Arial','',9);
$pdf->Cell(90, 6, pdf_text('______________________________'), 0, 0, 'C');
$pdf->Cell(10, 6, '', 0, 0);
$pdf->Cell(90, 6, pdf_text('______________________________'), 0, 1, 'C');

$pdf->Cell(90, 6, pdf_text($inspectorNombre), 0, 0, 'C');
$pdf->Cell(10, 6, '', 0, 0);
$pdf->Cell(90, 6, pdf_text($encargado), 0, 1, 'C');

$pdf->SetFont('Arial','I',8);
$pdf->Cell(90, 5, pdf_text('Inspector'), 0, 0, 'C');
$pdf->Cell(10, 5, '', 0, 0);
$pdf->Cell(90, 5, pdf_text('Encargado / Ingeniero'), 0, 1, 'C');

// ================================
// Página 2+ (Registro Fotográfico - Evidencias)
// ================================
$pdf->AddPage();
$pdf->SetFont('Arial','B',11);
$pdf->Cell(0, 7, pdf_text('Registro Fotográfico'), 0, 1, 'C');
$pdf->Ln(2);

addRegistroFotografico($pdf, $rutaImgDir, $imagenesNorm, 'Registro Fotográfico');

// ================================
// Página 3+ (Adjuntos - Imágenes/Capturas aparte de evidencia)
// ================================
if (!empty($adjuntosNorm)) {
    $pdf->AddPage();
    $pdf->SetFont('Arial','B',11);
    $pdf->Cell(0, 7, pdf_text('Adjuntos (Imágenes / Capturas)'), 0, 1, 'C');
    $pdf->Ln(2);

    addRegistroFotografico($pdf, $rutaAdjDir, $adjuntosNorm, 'Adjuntos (Imágenes / Capturas)');
}

// ================================
// Página Anexos (Documentos/Firmados)
// ================================
if (!empty($documentos) || !empty($firmados)) {
    $pdf->AddPage();
    $pdf->SetFont('Arial','B',11);
    $pdf->Cell(0, 7, pdf_text('Anexos / Documentos'), 0, 1, 'C');
    $pdf->Ln(2);

    print_list($pdf, 'Documentos', $documentos);
    $pdf->Ln(2);
    print_list($pdf, 'Firmados (PDF)', $firmados);

    $pdf->Ln(3);
    $pdf->SetFont('Arial','I',8);
    $pdf->MultiCell(0, 4, pdf_text('Nota: Si se requiere evidenciar tablas o cálculos (Excel/Word), adjunte además un pantallazo como imagen dentro del Registro Fotográfico o en Adjuntos.'), 0, 'L');
}

// ================================
// Salida
// ================================
$filename = "cronica_" . preg_replace('/[^A-Za-z0-9_\-]/', '_', $consecutivoMostrar) . ".pdf";

header('Content-Type: application/pdf');
header('Content-Disposition: inline; filename="' . $filename . '"');
header('Cache-Control: private, max-age=0, must-revalidate');
header('Pragma: public');

$pdf->Output('I', $filename);
exit;
