<?php
require_once "auth.php";
require_login();

require_once __DIR__ . "/config/db.php";
header('Content-Type: text/html; charset=utf-8');
mysqli_set_charset($conn, "utf8");

// Sesión
$nombre = $_SESSION["nombre"] ?? "Usuario";
$rol    = strtolower(trim($_SESSION["rol"] ?? "vista"));
$uid    = (int)($_SESSION["id"] ?? 0);

// Nombre bonito del rol
$nombreRol = [
  "admin"     => "Administrador",
  "ingeniero" => "Ingeniero",
  "inspector" => "Inspector",
  "encargado" => "Encargado",
  "vista"     => "Visor",
  "invitado"  => "Invitado",
][$rol] ?? ucfirst($rol);

// Flags por rol
$esAdmin     = ($rol === "admin");
$esIng       = ($rol === "ingeniero");
$esInspector = ($rol === "inspector");
$esVista     = ($rol === "vista");
$esEncargado = ($rol === "encargado");

// Permisos (tarjetas / accesos)
$verProyectos   = ($esAdmin || $esIng);
$verCronicas    = ($esAdmin || $esInspector);
$verInventario  = ($esAdmin || $esIng);
$verHistorico   = ($esAdmin || $esIng);
$verAdminExtras = $esAdmin; // Papelera, Usuarios, Logs, etc.
$verSeguridad = $esAdmin;
$verPanelVisor  = $esVista;

// Helpers
function q1(mysqli $conn, string $sql, array $bind = [], string $types = "") {
  $stmt = $conn->prepare($sql);
  if ($stmt === false) return null;
  if (!empty($bind)) $stmt->bind_param($types, ...$bind);
  $stmt->execute();
  $res = $stmt->get_result();
  $row = $res ? $res->fetch_row() : null;
  $stmt->close();
  return $row ? $row[0] : null;
}

function badgeEstadoProyecto($estado) {
  $v = (string)$estado;
  if ($v === "1") return ["Pendiente", "info"];
  if ($v === "2") return ["En ejecución", "warning"];
  if ($v === "3") return ["Finalizado", "success"];
  if ($v === "0") return ["Papelera", "secondary"];
  return ["Sin estado", "secondary"];
}

// Criterios consistentes para dashboard
$sqlProyActivos = "SELECT COUNT(*) FROM proyectos WHERE (estado IS NULL OR estado <> '0') AND (activo IS NULL OR activo = 1)";
$sqlProyEjec    = "SELECT COUNT(*) FROM proyectos WHERE (estado = '2' OR estado = 2) AND (estado IS NULL OR estado <> '0') AND (activo IS NULL OR activo = 1)";
$sqlProyFin     = "SELECT COUNT(*) FROM proyectos WHERE (estado = '3' OR estado = 3) AND (estado IS NULL OR estado <> '0') AND (activo IS NULL OR activo = 1)";
$sqlAvgAvance   = "SELECT COALESCE(ROUND(AVG(COALESCE(avance,0))),0) FROM proyectos WHERE (estado IS NULL OR estado <> '0') AND (activo IS NULL OR activo = 1)";
$sqlMontoTotal  = "SELECT COALESCE(SUM(COALESCE(monto_invertido,0)),0) FROM proyectos WHERE (estado IS NULL OR estado <> '0') AND (activo IS NULL OR activo = 1)";

// Crónicas activas ligadas a proyectos activos
$sqlCronActivas = "
  SELECT COUNT(*)
  FROM cronicas c
  INNER JOIN proyectos p ON p.id = c.proyecto_id
    AND (p.estado IS NULL OR p.estado <> '0')
    AND (p.activo IS NULL OR p.activo = 1)
  WHERE (c.estado_registro IS NULL OR (c.estado_registro <> '0' AND c.estado_registro <> 'papelera'))
";

$sqlPapeleraProy = "SELECT COUNT(*) FROM proyectos WHERE (estado = '0' OR estado = 0 OR activo = 0)";
$sqlPapeleraCro  = "
  SELECT COUNT(*)
  FROM cronicas c
  LEFT JOIN proyectos p ON p.id = c.proyecto_id
  WHERE (c.estado_registro = '0' OR c.estado_registro = 'papelera')
     OR (p.estado = '0' OR p.estado = 0 OR p.activo = 0)
";

// KPIs por rol
$kpis = [];

if ($esAdmin) {
  $kpis[] = ["Proyectos activos", (int)q1($conn, $sqlProyActivos), "bi-diagram-3", "primary"];
  $kpis[] = ["En ejecución",     (int)q1($conn, $sqlProyEjec),    "bi-hourglass-split", "warning"];
  $kpis[] = ["Crónicas activas",  (int)q1($conn, $sqlCronActivas), "bi-journal-text", "success"];
  $pap = (int)q1($conn, $sqlPapeleraProy) + (int)q1($conn, $sqlPapeleraCro);
  $kpis[] = ["En papelera", $pap, "bi-trash3", "danger"];
} elseif ($esIng) {
  $kpis[] = ["Proyectos activos", (int)q1($conn, $sqlProyActivos), "bi-diagram-3", "primary"];
  $kpis[] = ["En ejecución",     (int)q1($conn, $sqlProyEjec),    "bi-hourglass-split", "warning"];
  $kpis[] = ["Avance promedio",  (int)q1($conn, $sqlAvgAvance) . "%", "bi-speedometer2", "info"];
  $monto = (float)q1($conn, $sqlMontoTotal);
  $kpis[] = ["Monto invertido",  "₡ " . number_format($monto, 0, ',', '.'), "bi-cash-coin", "success"];
} elseif ($esInspector) {
  $sqlMisCronAct = "
    SELECT COUNT(*)
    FROM cronicas c
    INNER JOIN proyectos p ON p.id = c.proyecto_id
      AND (p.estado IS NULL OR p.estado <> '0')
      AND (p.activo IS NULL OR p.activo = 1)
    WHERE c.usuario_id = ?
      AND (c.estado_registro IS NULL OR (c.estado_registro <> '0' AND c.estado_registro <> 'papelera'))
  ";
  $sqlMisCronMes = "
    SELECT COUNT(*)
    FROM cronicas c
    WHERE c.usuario_id = ?
      AND YEAR(c.fecha) = YEAR(CURDATE())
      AND MONTH(c.fecha) = MONTH(CURDATE())
      AND (c.estado_registro IS NULL OR (c.estado_registro <> '0' AND c.estado_registro <> 'papelera'))
  ";
  $kpis[] = ["Mis crónicas activas", (int)q1($conn, $sqlMisCronAct, [$uid], "i"), "bi-journal-text", "success"];
  $kpis[] = ["Mis crónicas del mes", (int)q1($conn, $sqlMisCronMes, [$uid], "i"), "bi-calendar-event", "info"];
  $kpis[] = ["Proyectos activos",    (int)q1($conn, $sqlProyActivos), "bi-diagram-3", "primary"];
  $kpis[] = ["Pendientes offline",  "—", "bi-cloud-slash", "warning"]; // se llena con JS
} else {
  $kpis[] = ["Proyectos activos", (int)q1($conn, $sqlProyActivos), "bi-diagram-3", "primary"];
  $kpis[] = ["Crónicas activas",  (int)q1($conn, $sqlCronActivas), "bi-journal-text", "success"];
  $kpis[] = ["Finalizados",       (int)q1($conn, $sqlProyFin),     "bi-check2-circle", "info"];
  $kpis[] = ["Estado",            "Lectura", "bi-eye", "secondary"];
}

// Actividad reciente por rol
$recent = [];

if ($esAdmin) {
  $recentSql = "SELECT fecha, usuario, accion, ip FROM logs_acciones ORDER BY fecha DESC LIMIT 8";
  $rs = $conn->query($recentSql);
  if ($rs) while ($r = $rs->fetch_assoc()) $recent[] = $r;
} elseif ($esInspector) {
  $recentSql = "
    SELECT c.id, c.consecutivo, c.fecha, p.nombre AS proyecto
    FROM cronicas c
    INNER JOIN proyectos p ON p.id = c.proyecto_id
    WHERE c.usuario_id = ?
    ORDER BY c.id DESC
    LIMIT 8
  ";
  $stmt = $conn->prepare($recentSql);
  if ($stmt) {
    $stmt->bind_param("i", $uid);
    $stmt->execute();
    $rs = $stmt->get_result();
    while ($rs && ($r = $rs->fetch_assoc())) $recent[] = $r;
    $stmt->close();
  }
} elseif ($esIng) {
  $recentSql = "
    SELECT id, nombre, estado, fecha_inicio, avance
    FROM proyectos
    WHERE (estado IS NULL OR estado <> '0') AND (activo IS NULL OR activo = 1)
    ORDER BY id DESC
    LIMIT 8
  ";
  $rs = $conn->query($recentSql);
  if ($rs) while ($r = $rs->fetch_assoc()) $recent[] = $r;
} else {
  $recent["proyectos"] = [];
  $recent["cronicas"]  = [];

  $recentSqlP = "
    SELECT id, nombre, estado, fecha_inicio
    FROM proyectos
    WHERE (estado IS NULL OR estado <> '0') AND (activo IS NULL OR activo = 1)
    ORDER BY id DESC
    LIMIT 5
  ";
  $recentSqlC = "
    SELECT c.id, c.consecutivo, c.fecha, p.nombre AS proyecto
    FROM cronicas c
    INNER JOIN proyectos p ON p.id = c.proyecto_id
      AND (p.estado IS NULL OR p.estado <> '0')
      AND (p.activo IS NULL OR p.activo = 1)
    WHERE (c.estado_registro IS NULL OR (c.estado_registro <> '0' AND c.estado_registro <> 'papelera'))
    ORDER BY c.id DESC
    LIMIT 5
  ";
  $rs1 = $conn->query($recentSqlP);
  if ($rs1) while ($r = $rs1->fetch_assoc()) $recent["proyectos"][] = $r;

  $rs2 = $conn->query($recentSqlC);
  if ($rs2) while ($r = $rs2->fetch_assoc()) $recent["cronicas"][] = $r;
}

// Gráfico: proyectos por estado
$chart = ["1"=>0, "2"=>0, "3"=>0];
$rsChart = $conn->query("
  SELECT CAST(estado AS CHAR) AS estado, COUNT(*) AS n
  FROM proyectos
  WHERE (estado IS NULL OR estado <> '0') AND (activo IS NULL OR activo = 1)
  GROUP BY estado
");
if ($rsChart) {
  while ($row = $rsChart->fetch_assoc()) {
    $k = (string)($row["estado"] ?? "");
    if (isset($chart[$k])) $chart[$k] = (int)$row["n"];
  }
}
$maxChart = max(1, max($chart));

include __DIR__ . "/includes/header.php";
?>

<style>
  :root{
    --bg: #f6f8fb;
    --card: #ffffff;
    --muted: #6b7280;
    --border: rgba(0,0,0,.06);
    --shadow: 0 10px 30px rgba(0,0,0,.06);
    --shadow-hover: 0 16px 44px rgba(0,0,0,.10);
  }

  body{
    background: radial-gradient(1200px 500px at 20% -10%, rgba(13,110,253,.12), transparent),
                radial-gradient(900px 420px at 90% 10%, rgba(25,135,84,.10), transparent),
                var(--bg);
    font-family: "Segoe UI", system-ui, -apple-system, sans-serif;
    min-height: 100vh;
  }

  .wrap{ max-width: 1150px; }

  .topbar{
    background: rgba(255,255,255,.75);
    border: 1px solid var(--border);
    backdrop-filter: blur(10px);
    border-radius: 18px;
    box-shadow: var(--shadow);
  }

  .kpi-card, .action-card, .panel-card{
    border: 1px solid var(--border);
    border-radius: 18px;
    background: var(--card);
    box-shadow: var(--shadow);
  }

  .action-card{
    transition: transform .18s ease, box-shadow .18s ease;
  }
  .action-card:hover{
    transform: translateY(-4px);
    box-shadow: var(--shadow-hover);
  }

  .kpi-value{
    font-size: 1.6rem;
    line-height: 1.2;
  }
  .muted{ color: var(--muted); }
  .tiny{ font-size: .86rem; }

  .pill{
    border: 1px solid var(--border);
    background: rgba(255,255,255,.6);
    border-radius: 999px;
    padding: .25rem .6rem;
  }

  .list-clean .item{
    border-bottom: 1px dashed rgb(0, 0, 0);
    padding: .65rem 0;
  }
  .list-clean .item:last-child{ border-bottom: 0; }

  .icon-soft{
    width: 44px;
    height: 44px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    border-radius: 14px;
    background: rgba(0,0,0,.04);
    border: 1px solid rgba(0,0,0,.06);
  }

  .bar-row{
    display:grid;
    grid-template-columns: 120px 1fr 52px;
    gap:10px;
    align-items:center;
    margin: 10px 0;
  }
  .bar-track{
    height:10px;
    border-radius:999px;
    background:#eef2f7;
    border:1px solid rgba(0,0,0,.05);
    overflow:hidden;
  }
  .bar-fill{
    height:100%;
    border-radius:999px;
    width:0%;
  }
  
</style>

<main class="container wrap py-4 py-md-5">

  <div class="topbar p-3 p-md-4 mb-4">
    <div class="d-flex flex-wrap align-items-center justify-content-between gap-3">
      <div>
        <div class="text-primary tiny">Gestión Vial</div>
        <div class="h4 mb-1">Bienvenido, <?= htmlspecialchars($nombre, ENT_QUOTES, 'UTF-8') ?></div>
        <div class="d-flex flex-wrap gap-2 align-items-center">
          <span class="badge bg-secondary text-uppercase"><?= htmlspecialchars($nombreRol, ENT_QUOTES, 'UTF-8') ?></span>
          <span class="pill tiny muted" id="netStatus">Conexión: —</span>
          <span class="pill tiny muted" id="offlinePending">Pendientes offline: —</span>
        </div>
      </div>

      <div class="d-flex gap-2">
        <a href="logout.php" class="btn btn-outline-danger">
          <i class="bi bi-door-closed"></i> Salir
        </a>
      </div>
    </div>
  </div>

  <div class="row g-3 mb-4">
    <?php foreach ($kpis as $k): ?>
      <div class="col-12 col-md-6 col-lg-3">
        <div class="kpi-card p-3 h-100">
          <div class="d-flex align-items-start justify-content-between">
            <div>
              <div class="tiny muted"><?= htmlspecialchars($k[0], ENT_QUOTES, 'UTF-8') ?></div>
              <div class="kpi-value mt-1"><?= htmlspecialchars((string)$k[1], ENT_QUOTES, 'UTF-8') ?></div>
            </div>
            <div class="icon-soft">
              <i class="bi <?= htmlspecialchars($k[2], ENT_QUOTES, 'UTF-8') ?> text-<?= htmlspecialchars($k[3], ENT_QUOTES, 'UTF-8') ?> fs-4"></i>
            </div>
          </div>
        </div>
      </div>
    <?php endforeach; ?>
  </div>

  <div class="row g-3 mb-4">
    <?php if ($verProyectos): ?>
      <div class="col-12 col-md-6 col-lg-3">
        <a class="text-decoration-none text-dark" href="pages/proyectos/proyectos.php">
          <div class="action-card p-3 h-100">
            <div class="d-flex gap-3 align-items-center">
              <div class="icon-soft"><i class="bi bi-diagram-3 text-primary fs-4"></i></div>
              <div>
                <div class="fw-semibold">Proyectos</div>
                <div class="tiny muted">Gestión y seguimiento</div>
              </div>
            </div>
          </div>
        </a>
      </div>
    <?php endif; ?>

    <?php if ($verCronicas): ?>
      <div class="col-12 col-md-6 col-lg-3">
        <a class="text-decoration-none text-dark" href="pages/cronicas/cronicas.php">
          <div class="action-card p-3 h-100">
            <div class="d-flex gap-3 align-items-center">
              <div class="icon-soft"><i class="bi bi-journal-text text-success fs-4"></i></div>
              <div>
                <div class="fw-semibold">Crónicas</div>
                <div class="tiny muted">Registro de inspecciones</div>
              </div>
            </div>
          </div>
        </a>
      </div>
    <?php endif; ?>

    <?php if ($verInventario): ?>
      <div class="col-12 col-md-6 col-lg-3">
        <a class="text-decoration-none text-dark" href="pages/caminos/caminos.php">
          <div class="action-card p-3 h-100">
            <div class="d-flex gap-3 align-items-center">
              <div class="icon-soft"><i class="bi bi-map text-warning fs-4"></i></div>
              <div>
                <div class="fw-semibold">Inventario</div>
                <div class="tiny muted">Caminos y tipologías</div>
              </div>
            </div>
          </div>
        </a>
      </div>
    <?php endif; ?>

    <?php if ($verHistorico): ?>
      <div class="col-12 col-md-6 col-lg-3">
        <a class="text-decoration-none text-dark" href="pages/historico/historico.php">
          <div class="action-card p-3 h-100">
            <div class="d-flex gap-3 align-items-center">
              <div class="icon-soft"><i class="bi bi-clock-history text-info fs-4"></i></div>
              <div>
                <div class="fw-semibold">Histórico</div>
                <div class="tiny muted">Consulta general</div>
              </div>
            </div>
          </div>
        </a>
      </div>
    <?php endif; ?>

    <?php if ($verAdminExtras): ?>
      <div class="col-12 col-md-6 col-lg-3">
        <a class="text-decoration-none text-dark" href="pages/papelera/papelera.php">
          <div class="action-card p-3 h-100">
            <div class="d-flex gap-3 align-items-center">
              <div class="icon-soft"><i class="bi bi-trash3 text-danger fs-4"></i></div>
              <div>
                <div class="fw-semibold">Papelera</div>
                <div class="tiny muted">Restaurar o eliminar</div>
              </div>
            </div>
          </div>
        </a>
      </div>

      <div class="col-12 col-md-6 col-lg-3">
        <a class="text-decoration-none text-dark" href="pages/logs/logs.php">
          <div class="action-card p-3 h-100">
            <div class="d-flex gap-3 align-items-center">
              <div class="icon-soft"><i class="bi bi-file-earmark-text text-secondary fs-4"></i></div>
              <div>
                <div class="fw-semibold">Logs</div>
                <div class="tiny muted">Auditoría del sistema</div>
              </div>
            </div>
          </div>
        </a>
      </div>

      <div class="col-12 col-md-6 col-lg-3">
        <a class="text-decoration-none text-dark" href="pages/usuarios/usuarios.php">
          <div class="action-card p-3 h-100">
            <div class="d-flex gap-3 align-items-center">
              <div class="icon-soft"><i class="bi bi-person-gear text-primary fs-4"></i></div>
              <div>
                <div class="fw-semibold">Usuarios</div>
                <div class="tiny muted">Roles y accesos</div>
              </div>
            </div>
          </div>
        </a>
      </div>

      <div class="col-12 col-md-6 col-lg-3">
        <a class="text-decoration-none text-dark" href="pages/seguridad/seguridad_logs.php">
          <div class="action-card p-3 h-100">
            <div class="d-flex gap-3 align-items-center">
              <div class="icon-soft"><i class="bi bi-shield-lock text-primary fs-4"></i></div>
              <div>
                <div class="fw-semibold">Seguridad</div>
                <div class="tiny muted">Seguridad del Sistema</div>
              </div>
            </div>
          </div>
        </a>
      </div>
    <?php endif; ?>

    <?php if ($verPanelVisor): ?>
      <div class="col-12 col-md-6 col-lg-3">
        <a class="text-decoration-none text-dark" href="pages/visor/panel_visor.php">
          <div class="action-card p-3 h-100">
            <div class="d-flex gap-3 align-items-center">
              <div class="icon-soft"><i class="bi bi-eye text-success fs-4"></i></div>
              <div>
                <div class="fw-semibold">Panel visor</div>
                <div class="tiny muted">Vista resumida</div>
              </div>
            </div>
          </div>
        </a>
      </div>
    <?php endif; ?>
  </div>


  <div class="row g-3">
    <div class="col-12 col-lg-7">
      <div class="panel-card p-3 p-md-4 h-100">
        <div class="d-flex justify-content-between align-items-center mb-2">
          <div class="fw-semibold">Actividad reciente</div>
          <div class="tiny muted">
            <?php if ($esAdmin): ?>últimos logs<?php elseif ($esInspector): ?>mis crónicas<?php elseif ($esIng): ?>proyectos recientes<?php else: ?>resumen<?php endif; ?>
          </div>
        </div>

        <div class="list-clean">
          <?php if ($esAdmin): ?>
            
            <?php if (empty($recent)): ?>
              <div class="muted tiny">No hay actividad para mostrar.</div>
            <?php else: foreach ($recent as $r): ?>
              <div class="item">
                <div class="d-flex justify-content-between gap-3">
                  <div class="tiny">
                    <span class="badge bg-info text-dark"><?= htmlspecialchars($r["accion"] ?? "", ENT_QUOTES, 'UTF-8') ?></span>
                    <span class="ms-2"><?= htmlspecialchars($r["usuario"] ?? "", ENT_QUOTES, 'UTF-8') ?></span>
                    <span class="ms-2 muted">(<?= htmlspecialchars($r["ip"] ?? "", ENT_QUOTES, 'UTF-8') ?>)</span>
                  </div>
                  <div class="tiny muted">
                    <?= !empty($r["fecha"]) ? htmlspecialchars(date("d/m/Y H:i", strtotime($r["fecha"])), ENT_QUOTES, 'UTF-8') : "" ?>
                  </div>
                </div>
              </div>
            <?php endforeach; endif; ?>

          <?php elseif ($esInspector): ?>
            <?php if (empty($recent)): ?>
              <div class="muted tiny">No hay crónicas recientes.</div>
            <?php else: foreach ($recent as $r): ?>
              <div class="item">
                <div class="d-flex justify-content-between gap-3">
                  <div class="tiny">
                    <span class="badge bg-success"><?= htmlspecialchars($r["consecutivo"] ?? "GV", ENT_QUOTES, 'UTF-8') ?></span>
                    <span class="ms-2"><?= htmlspecialchars($r["proyecto"] ?? "", ENT_QUOTES, 'UTF-8') ?></span>
                  </div>
                  <div class="tiny muted">
                    <?= !empty($r["fecha"]) ? htmlspecialchars(date("d/m/Y", strtotime($r["fecha"])), ENT_QUOTES, 'UTF-8') : "" ?>
                  </div>
                </div>
              </div>
            <?php endforeach; endif; ?>

          <?php elseif ($esIng): ?>
            <?php if (empty($recent)): ?>
              <div class="muted tiny">No hay proyectos recientes.</div>
            <?php else: foreach ($recent as $r): ?>
              <?php [$txtE, $colorE] = badgeEstadoProyecto($r["estado"] ?? ""); ?>
              <div class="item">
                <div class="d-flex justify-content-between gap-3">
                  <div class="tiny">
                    <span class="badge bg-<?= $colorE ?>"><?= htmlspecialchars($txtE, ENT_QUOTES, 'UTF-8') ?></span>
                    <span class="ms-2"><?= htmlspecialchars($r["nombre"] ?? "", ENT_QUOTES, 'UTF-8') ?></span>
                    <span class="ms-2 muted"><?= (int)($r["avance"] ?? 0) ?>%</span>
                  </div>
                  <div class="tiny muted">
                    <?= !empty($r["fecha_inicio"]) ? htmlspecialchars(date("d/m/Y", strtotime($r["fecha_inicio"])), ENT_QUOTES, 'UTF-8') : "" ?>
                  </div>
                </div>
              </div>
            <?php endforeach; endif; ?>

          <?php else: ?>
            <div class="row g-3">
              <div class="col-12 col-md-6">
                <div class="tiny muted mb-2">proyectos</div>
                <div class="list-clean">
                  <?php if (empty($recent["proyectos"])): ?>
                    <div class="muted tiny">No hay proyectos.</div>
                  <?php else: foreach ($recent["proyectos"] as $p): ?>
                    <?php [$txtE, $colorE] = badgeEstadoProyecto($p["estado"] ?? ""); ?>
                    <div class="item">
                      <div class="d-flex justify-content-between gap-3">
                        <div class="tiny">
                          <span class="badge bg-<?= $colorE ?>"><?= htmlspecialchars($txtE, ENT_QUOTES, 'UTF-8') ?></span>
                          <span class="ms-2"><?= htmlspecialchars($p["nombre"] ?? "", ENT_QUOTES, 'UTF-8') ?></span>
                        </div>
                        <div class="tiny muted">
                          <?= !empty($p["fecha_inicio"]) ? htmlspecialchars(date("d/m/Y", strtotime($p["fecha_inicio"])), ENT_QUOTES, 'UTF-8') : "" ?>
                        </div>
                      </div>
                    </div>
                  <?php endforeach; endif; ?>
                </div>
              </div>

              <div class="col-12 col-md-6">
                <div class="tiny muted mb-2">crónicas</div>
                <div class="list-clean">
                  <?php if (empty($recent["cronicas"])): ?>
                    <div class="muted tiny">No hay crónicas.</div>
                  <?php else: foreach ($recent["cronicas"] as $c): ?>
                    <div class="item">
                      <div class="d-flex justify-content-between gap-3">
                        <div class="tiny">
                          <span class="badge bg-success"><?= htmlspecialchars($c["consecutivo"] ?? "GV", ENT_QUOTES, 'UTF-8') ?></span>
                          <span class="ms-2"><?= htmlspecialchars($c["proyecto"] ?? "", ENT_QUOTES, 'UTF-8') ?></span>
                        </div>
                        <div class="tiny muted">
                          <?= !empty($c["fecha"]) ? htmlspecialchars(date("d/m/Y", strtotime($c["fecha"])), ENT_QUOTES, 'UTF-8') : "" ?>
                        </div>
                      </div>
                    </div>
                  <?php endforeach; endif; ?>
                </div>
              </div>
            </div>
          <?php endif; ?>
        </div>

      </div>
    </div>

    <div class="col-12 col-lg-5">
      <div class="panel-card p-3 p-md-4 h-100">
        <div class="fw-semibold mb-2">Proyectos por estado</div>

        <?php
          $defs = [
            "1" => ["Pendiente", "bg-info"],
            "2" => ["En ejecución", "bg-warning"],
            "3" => ["Finalizado", "bg-success"],
          ];
        ?>

        <?php foreach ($defs as $k => $def): ?>
          <?php
            $label = $def[0];
            $barClass = $def[1];
            $n = (int)$chart[$k];
            $pct = (int)round(($n / $maxChart) * 100);
          ?>
          <div class="bar-row">
            <div class="tiny muted"><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></div>
            <div class="bar-track">
              <div class="bar-fill <?= $barClass ?>" style="width: <?= $pct ?>%;"></div>
            </div>
            <div class="tiny muted text-end"><?= $n ?></div>
          </div>
        <?php endforeach; ?>

        <hr class="my-3">

        <div class="fw-semibold mb-2">Acciones rápidas</div>

        <div class="d-grid gap-2">
          <?php if ($esInspector): ?>
            <a href="pages/cronicas/cronicas.php" class="btn btn-success">
              <i class="bi bi-plus-circle"></i> Nueva crónica
            </a>
            <button type="button" class="btn btn-outline-warning" onclick="forzarSyncDashboard()">
              <i class="bi bi-arrow-repeat"></i> Sincronizar ahora
            </button>
            <div class="tiny muted" id="syncHint">
              Si estás sin conexión, podés guardar offline y sincronizar después.
            </div>
          <?php elseif ($esAdmin): ?>
            <a href="pages/papelera/papelera.php" class="btn btn-outline-danger">
              <i class="bi bi-trash3"></i> Ir a papelera
            </a>
            <a href="pages/logs/logs.php" class="btn btn-outline-secondary">
              <i class="bi bi-file-earmark-text"></i> Ver logs
            </a>
            <a href="pages/usuarios/usuarios.php" class="btn btn-outline-primary">
              <i class="bi bi-person-gear"></i> Gestionar usuarios
            </a>
            <a href="pages/seguridad/seguridad_logs.php" class="btn btn-outline-primary">
              <i class="bi bi-shield-lock"></i> Seguridad
            </a>
          <?php elseif ($esIng): ?>
            <a href="pages/proyectos/proyectos.php" class="btn btn-outline-primary">
              <i class="bi bi-diagram-3"></i> Abrir proyectos
            </a>
            <a href="pages/historico/historico.php" class="btn btn-outline-info">
              <i class="bi bi-clock-history"></i> Ver histórico
            </a>
            <a href="pages/caminos/caminos.php" class="btn btn-outline-warning">
              <i class="bi bi-map"></i> Inventario de caminos
            </a>
          <?php else: ?>
            <?php if ($verPanelVisor): ?>
              <a href="pages/visor/panel_visor.php" class="btn btn-outline-success">
                <i class="bi bi-eye"></i> Panel visor
              </a>
            <?php endif; ?>
            <?php if ($verProyectos): ?>
              <a href="pages/proyectos/proyectos.php" class="btn btn-outline-primary">
                <i class="bi bi-diagram-3"></i> Proyectos
              </a>
            <?php endif; ?>
            <?php if ($verCronicas): ?>
              <a href="pages/cronicas/cronicas.php" class="btn btn-outline-success">
                <i class="bi bi-journal-text"></i> Crónicas
              </a>
            <?php endif; ?>
          <?php endif; ?>
        </div>

      </div>
    </div>
  </div>

</main>

<?php
// Cargar scripts opcionales (evita 404 si no existen)
if (file_exists(__DIR__ . "/js/gestor-offline.js")) {
  echo '<script src="js/gestor-offline.js"></script>';
}
if (file_exists(__DIR__ . "/js/app.js")) {
  echo '<script src="js/app.js"></script>';
}
?>

<script>
  function setNetBadges() {
    const net = document.getElementById('netStatus');
    const off = document.getElementById('offlinePending');

    const online = navigator.onLine;
    net.textContent = 'Conexión: ' + (online ? 'En línea' : 'Sin conexión');
    net.classList.toggle('text-success', online);
    net.classList.toggle('text-danger', !online);

    let pendientes = 0;
    try {
      const arr = JSON.parse(localStorage.getItem('cronicas_pendientes') || '[]');
      pendientes = Array.isArray(arr) ? arr.length : 0;
    } catch (e) { pendientes = 0; }

    off.textContent = 'Pendientes offline: ' + pendientes;

    <?php if ($esInspector): ?>
      const kpiOffline = document.querySelectorAll('.kpi-value')[3];
      if (kpiOffline) kpiOffline.textContent = String(pendientes);
    <?php endif; ?>
  }

  window.addEventListener('load', setNetBadges);
  window.addEventListener('online', setNetBadges);
  window.addEventListener('offline', setNetBadges);

  function forzarSyncDashboard() {
    if (window.offlineSystem && typeof window.offlineSystem.syncOfflineData === 'function') {
      window.offlineSystem.syncOfflineData();
      setTimeout(setNetBadges, 800);
    } else {
      alert('Sistema offline no inicializado.');
    }
  }
</script>

<?php include __DIR__ . "/includes/footer.php"; ?>