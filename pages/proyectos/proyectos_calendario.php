<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once "../../config/db.php";
require_once "../../auth.php";

// 1) Login
require_login();

// 2) Permisos (igual que proyectos.php)
if (!can_edit("proyectos") && !can_edit("proyectos_ver")) {
  echo "<div class='alert alert-danger m-3'>Sin permisos para ver proyectos.</div>";
  exit;
}

// 3) Charset / headers ANTES de HTML
header('Content-Type: text/html; charset=utf-8');
mysqli_set_charset($conn, "utf8");

include "../../includes/header.php";
?>
<div class="container py-4">

  <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <div>
      <h3 class="fw-bold text-primary mb-0">
        <i class="bi bi-calendar3"></i> Calendario de Proyectos
      </h3>
      <div class="text-muted" style="font-size:.92rem;">
        Proyectos por fecha de inicio y fin. Colores por estado.
      </div>
    </div>

    <div class="d-flex gap-2">
      <a href="proyectos.php" class="btn btn-secondary">
        <i class="bi bi-arrow-left"></i> Volver
      </a>
    </div>
  </div>

  <!-- Filtros simples -->
  <div class="card shadow-sm mb-3">
    <div class="card-body">
      <div class="row g-2 align-items-end">
        <div class="col-12 col-md-4">
          <label class="form-label mb-1">Estado</label>
          <select id="fEstado" class="form-select">
            <option value="">Todos</option>
            <option value="1">Pendiente</option>
            <option value="2">En ejecución</option>
            <option value="3">Finalizado</option>
          </select>
        </div>

        <div class="col-12 col-md-6">
          <label class="form-label mb-1">Buscar</label>
          <input id="fBuscar" class="form-control" placeholder="Nombre, camino, distrito o encargado">
        </div>

        <div class="col-12 col-md-2 d-grid">
          <button id="btnAplicar" class="btn btn-primary fw-bold">
            <i class="bi bi-funnel"></i> Aplicar
          </button>
        </div>
      </div>

      <hr class="my-3">

      <div class="d-flex flex-wrap gap-3 small text-muted">
        <div class="d-flex align-items-center gap-2">
          <span style="width:14px;height:14px;background:#0d6efd;display:inline-block;border-radius:3px;"></span>
          Pendiente
        </div>
        <div class="d-flex align-items-center gap-2">
          <span style="width:14px;height:14px;background:#fd7e14;display:inline-block;border-radius:3px;"></span>
          En ejecución
        </div>
        <div class="d-flex align-items-center gap-2">
          <span style="width:14px;height:14px;background:#198754;display:inline-block;border-radius:3px;"></span>
          Finalizado
        </div>
      </div>
    </div>
  </div>

  <div class="card shadow-sm">
    <div class="card-body">
      <div id="calendar"></div>
    </div>
  </div>

</div>

<!-- FullCalendar por CDN -->
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.15/index.global.min.css">
<script src="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.15/index.global.min.js"></script>

<script>
(function(){
  const calendarEl = document.getElementById('calendar');
  const fEstado = document.getElementById('fEstado');
  const fBuscar = document.getElementById('fBuscar');
  const btnAplicar = document.getElementById('btnAplicar');

  function buildUrl() {
    const q = encodeURIComponent(fBuscar.value.trim());
    const estado = encodeURIComponent(fEstado.value);
    return `proyectos_eventos.php?q=${q}&estado=${estado}`;
  }

  const calendar = new FullCalendar.Calendar(calendarEl, {
    initialView: 'dayGridMonth',
    height: 'auto',
    locale: 'es',
    firstDay: 1,
    headerToolbar: {
      left: 'prev,next today',
      center: 'title',
      right: 'dayGridMonth,timeGridWeek,timeGridDay,listMonth'
    },
    navLinks: true,
    dayMaxEvents: true,

    eventSources: [
      {
        url: buildUrl(),
        method: 'GET',
        failure: function() {
          alert('No se pudieron cargar los proyectos para el calendario.');
        }
      }
    ],

    eventClick: function(info) {
      // si viene url, navegamos al visor
      if (info.event.url) {
        info.jsEvent.preventDefault();
        window.location.href = info.event.url;
      }
    }
  });

  calendar.render();

  btnAplicar.addEventListener('click', function(){
    // refetch con la nueva URL
    calendar.getEventSources().forEach(s => s.remove());
    calendar.addEventSource({ url: buildUrl(), method: 'GET' });
    calendar.refetchEvents();
  });
})();
</script>

<?php include "../../includes/footer.php"; ?>
