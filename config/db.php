<?php
// ===============================================
// CONFIGURACIÓN DE CONEXIÓN A LA BASE DE DATOS
// ===============================================

// Parámetros de conexión
$servername = "localhost";
$username   = "admin_vial";
$password   = "Admin123!";
$database   = "gestion_vial_test";
$port       = 3306;

// Crear conexión
$conn = new mysqli($servername, $username, $password, $database, $port);

// Verificar conexión
if ($conn->connect_error) {
    die("❌ Error de conexión: " . $conn->connect_error);
}
?>
