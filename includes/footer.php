<footer class="footer mt-auto py-3 text-center text-white">
  <div class="container">

    <p class="mb-0">

      <!-- Calendario antes del año -->
      <span class="footer-sep">📅</span>
      <?= "Año " . date("Y"); ?>
      &nbsp;·&nbsp;

      <!-- Ícono de ubicación clickeable -->
      <a href="https://maps.app.goo.gl/uwf9Skdf5c9HmKKx9"
         target="_blank"
         class="footer-location-link"
         title="Ver ubicación en Google Maps">
        <i class="bi bi-geo-alt-fill"></i>
      </a>

      Municipalidad de Buenos Aires
      &nbsp;·&nbsp;
      Puntarenas, Costa Rica
    </p>

    <small class="text-light opacity-75">
      Desarrollado por el Departamento de TI
    </small>

  </div>
</footer>

<style>
  .footer {
    background: linear-gradient(90deg, #0d6efd, #2563eb);
    font-size: 0.95rem;
    width: 100%;
    margin-top: 40px;
  }

  html, body {
    height: 100%;
    display: flex;
    flex-direction: column;
  }

  footer {
    margin-top: auto;
  }

  /* Ícono de ubicación */
  .footer-location-link i {
    color: #ffc107;
    font-size: 1.2rem;
    margin-right: 4px;
    cursor: pointer;
  }

  .footer-location-link:hover i {
    color: #fff;
  }

  .footer-sep {
    margin-right: 6px;
  }
</style>
