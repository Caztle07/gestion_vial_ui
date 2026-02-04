<?php
// UTF-8 en TODO el stack (sin romper si ya hay headers)
if (!headers_sent()) {
  header('Content-Type: text/html; charset=UTF-8');
}

// Forzar conexión MySQL a utf8mb4 real
if (isset($conn) && $conn instanceof mysqli) {
  // Evita warnings silenciosos
  mysqli_report(MYSQLI_REPORT_OFF);
  $conn->set_charset('utf8mb4');
  // Asegura collation de la sesión
  $conn->query("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci");
  $conn->query("SET character_set_connection = utf8mb4");
  $conn->query("SET character_set_results = utf8mb4");
  $conn->query("SET character_set_client = utf8mb4");
}
