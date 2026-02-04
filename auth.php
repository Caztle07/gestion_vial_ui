<?php
// =========================
// SESIÓN
// =========================
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// =========================
// LOGIN OBLIGATORIO
// =========================
if (!function_exists('require_login')) {
    function require_login(): void
    {
        if (!isset($_SESSION["usuario"])) {
            header("Location: /gestion_vial_ui/login.php");
            exit;
        }
    }
}

// =========================================================
// LOGIN HELPERS (bcrypt + compat SHA256 y migración)
// =========================================================

/**
 * Intenta autenticar un usuario contra tabla `usuarios`.
 * - Soporta bcrypt (password_hash/password_verify)
 * - Soporta SHA256 legacy (hash('sha256')) y MIGRA a bcrypt si coincide
 *
 * Retorna:
 *  [ 'ok' => bool, 'error' => string|null, 'user' => array|null ]
 */
if (!function_exists('auth_try_login')) {
    function auth_try_login(mysqli $conn, string $usuario, string $password): array
    {
        $usuario = trim($usuario);

        if ($usuario === '' || $password === '') {
            return ['ok' => false, 'error' => 'Digite usuario y contraseña.', 'user' => null];
        }

        $stmt = $conn->prepare("SELECT id, usuario, nombre, rol, password FROM usuarios WHERE usuario=? LIMIT 1");
        if (!$stmt) {
            return ['ok' => false, 'error' => 'Error interno preparando consulta.', 'user' => null];
        }

        $stmt->bind_param("s", $usuario);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$row) {
            return ['ok' => false, 'error' => 'Usuario o contraseña incorrectos.', 'user' => null];
        }

        $hashBD = (string)($row['password'] ?? '');
        $ok = false;

        // Detecta si es bcrypt u otro algoritmo soportado por password_hash
        $info = password_get_info($hashBD);
        $esHashModerno = isset($info['algo']) && (int)$info['algo'] !== 0;

        if ($esHashModerno) {
            // bcrypt (recomendado)
            $ok = password_verify($password, $hashBD);

            // Si entra y el hash necesita rehash, lo actualiza
            if ($ok && password_needs_rehash($hashBD, PASSWORD_BCRYPT)) {
                $nuevo = password_hash($password, PASSWORD_BCRYPT);
                $up = $conn->prepare("UPDATE usuarios SET password=? WHERE id=?");
                if ($up) {
                    $id = (int)$row['id'];
                    $up->bind_param("si", $nuevo, $id);
                    $up->execute();
                    $up->close();
                }
            }
        } else {
            // Fallback legacy: SHA256 (tu formato anterior)
            $sha = hash('sha256', $password);
            if ($hashBD !== '' && hash_equals($hashBD, $sha)) {
                $ok = true;

                // Migrar automáticamente a bcrypt
                $nuevo = password_hash($password, PASSWORD_BCRYPT);
                $up = $conn->prepare("UPDATE usuarios SET password=? WHERE id=?");
                if ($up) {
                    $id = (int)$row['id'];
                    $up->bind_param("si", $nuevo, $id);
                    $up->execute();
                    $up->close();
                }
            }
            // --- CASO 3: Texto plano legacy ---
if (!$ok && $hashBD === $password) {
    $ok = true;

    // Migrar automáticamente a bcrypt
    $nuevo = password_hash($password, PASSWORD_BCRYPT);
    $up = $conn->prepare("UPDATE usuarios SET password=? WHERE id=?");
    if ($up) {
        $id = (int)$row['id'];
        $up->bind_param("si", $nuevo, $id);
        $up->execute();
        $up->close();
    }
}

        }

        if (!$ok) {
            return ['ok' => false, 'error' => 'Usuario o contraseña incorrectos.', 'user' => null];
        }

        return ['ok' => true, 'error' => null, 'user' => $row];
    }
}

/**
 * Setea las variables de sesión del usuario autenticado.
 */
if (!function_exists('auth_set_session')) {
    function auth_set_session(array $userRow): void
    {
        $_SESSION["id"]      = (int)($userRow["id"] ?? 0);
        $_SESSION["usuario"] = (string)($userRow["usuario"] ?? "");
        $_SESSION["nombre"]  = (string)($userRow["nombre"] ?? "");
        $_SESSION["rol"]     = (string)($userRow["rol"] ?? "vista");
    }
}

// =========================
// PERMISOS POR ROL
// =========================
/*
    ROLES Y REGLAS:

    - admin:
        • Puede hacer TODO
        • EXCEPTO ver / usar crónicas
          (no lista, no crear, no editar, no eliminar)

    - ingeniero:
        • Puede CREAR / EDITAR proyectos
        • Puede entrar a: logs, inspectores, histórico
        • No puede ver / usar crónicas

    - inspector:
        • Puede CREAR crónicas únicamente
        • No puede EDITAR ni borrar crónicas
        • No entra a proyectos, logs, etc.

    - vista:
        • Solo ver (modo lectura)
        • Puede ver el PANEL VISOR
        • Puede ver el HISTÓRICO (solo lectura)
*/
if (!function_exists('can_edit')) {
    function can_edit(string $permiso): bool
    {
        $rol = $_SESSION["rol"] ?? "vista";
        $permiso = trim($permiso);

        // ================================
        // 1) ADMIN → TODO MENOS CRÓNICAS
        // ================================
        if ($rol === "admin") {
            if (in_array($permiso, [
                "cronicas",
                "cronicas_crear",
                "cronicas_editar",
                "cronicas_eliminar",
                "cronicas_ver"
            ], true)) {
                return false;
            }
            return true;
        }

        // ==========================================
        // 2) INGENIERO → PROYECTOS + LOGS + INSPECTORES + HISTÓRICO
        // ==========================================
        if ($rol === "ingeniero") {
            if (in_array($permiso, [
                "proyectos",
                "proyectos_ver",
                "inventario",
                "caminos"
            ], true)) {
                return true;
            }

            if ($permiso === "logs") return true;
            if ($permiso === "inspectores") return true;

            if (in_array($permiso, ["historico", "historico_ver"], true)) {
                return true;
            }

            if (substr($permiso, 0, 8) === "cronicas") return false;
            if ($permiso === "admin") return false;

            return false;
        }

        // ==========================================
        // 3) INSPECTOR → SOLO CREAR CRÓNICAS
        // ==========================================
        if ($rol === "inspector") {
            if (in_array($permiso, [
                "cronicas",
                "cronicas_crear"
            ], true)) {
                return true;
            }

            if (in_array($permiso, [
                "cronicas_editar",
                "cronicas_eliminar",
                "proyectos",
                "logs",
                "inspectores",
                "historico",
                "admin"
            ], true)) {
                return false;
            }

            return false;
        }

        // ==========================================
        // 4) VISTA → SOLO VER (panel visor + histórico)
        // ==========================================
        if ($rol === "vista") {
            if (in_array($permiso, ["vista", "historico_ver"], true)) {
                return true;
            }
            return false;
        }
        
        // ==========================================
        // 5) ADMIN VEHICULAR → Control del módulo vehicular
        // ==========================================
        if ($rol === "adminvehicular") {
            if (in_array($permiso, [
                "vehiculos",
                "vehiculos_crear",
                "vehiculos_editar",
                "vehiculos_mantenimiento",
                "solicitudes_gestion",
                "entregas",
                "devoluciones",
                "reportes",
                "config_vehicular"
            ], true)) {
                return true;
            }
            return false;
        }

        // ==========================================
        // 6) SOLICITANTE → Puede solicitar vehículos
        // ==========================================
        if ($rol === "solicitante") {
            if (in_array($permiso, [
                "solicitudes_crear",
                "solicitudes_ver"
            ], true)) {
                return true;
            }
            return false;
        }

        // ==========================================
        // 7) FINANCIERO → Ve costos y reportes
        // ==========================================
        if ($rol === "financiero") {
            if (in_array($permiso, [
                "reportes_financieros",
                "costos_combustible",
                "devaluacion"
            ], true)) {
                return true;
            }
            return false;
        }

        // ==========================================
        // 8) DASHBOARD → Sólo lectura de reportes
        // ==========================================
        if ($rol === "dashboard") {
            if (in_array($permiso, [
                "dashboard_vehicular",
                "ver_reportes"
            ], true)) {
                return true;
            }
            return false;
        }

        // Rol desconocido → sin permisos
        return false;
    }
}

// =========================================================
// FUNCIÓN GLOBAL PARA REGISTRAR ACCIONES EN logs_acciones
// =========================================================
if (!function_exists('log_accion')) {
    function log_accion(mysqli $conn, string $accion, string $detalle = ''): void
    {
        $usuario_id = isset($_SESSION['id']) ? (int)$_SESSION['id'] : null;
        $usuario    = $_SESSION['usuario']   ?? ($_SESSION['nombre'] ?? 'desconocido');
        $rol        = $_SESSION['rol']       ?? 'desconocido';

        $ip = $_SERVER['REMOTE_ADDR'] ?? 'CLI';

        $hostname = @gethostbyaddr($ip);
        if ($hostname === false || $hostname === $ip) {
            $host_final = $ip;
        } else {
            $host_final = $hostname . ' (' . $ip . ')';
        }

        $accion = substr($accion, 0, 100);

        $sql = "INSERT INTO logs_acciones
                (usuario_id, usuario, rol, ip, accion, detalle, fecha)
                VALUES (?, ?, ?, ?, ?, ?, NOW())";

        if ($stmt = $conn->prepare($sql)) {
            if ($usuario_id && $usuario_id > 0) {
                $stmt->bind_param(
                    "isssss", 
                    $usuario_id, 
                    $usuario, 
                    $rol, 
                    $host_final, 
                    $accion, 
                    $detalle
                );
            } else {
                $null = null;
                $stmt->bind_param
                ("isssss", 
                $null, 
                $usuario, 
                $rol, 
                $host_final, 
                $accion, 
                $detalle
                );
            }

            $stmt->execute();
            $stmt->close();
        }
    }
}

// =============================================
// Registrar intentos de ingreso fallidos
// =============================================
if (!function_exists('registrar_intento_fallido')) {
    function registrar_intento_fallido(mysqli $conn, string $usuario, string $razon): void
    {
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'CLI';
        $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? 'desconocido';

        $usuario = substr($usuario, 0, 100);
        $razon = substr($razon, 0, 200);
        $user_agent = substr($user_agent, 0, 255);

        $stmt = $conn->prepare("
            INSERT INTO logs_seguridad (usuario, accion, fecha)
            VALUES (?, ?, NOW())
        ");

        $detalle = "Intento fallido: $razon | IP: $ip | UA: $user_agent";
        $stmt->bind_param("ss", $usuario, $detalle);
        $stmt->execute();
        $stmt->close();
    }
}

// =========================================================
// HELPER XSS: escapar salida HTML
// =========================================================
if (!function_exists('e')) {
    function e(?string $value): string
    {
        return htmlspecialchars($value ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

// =========================================================
// CSRF: generación y validación de token
// =========================================================
if (!function_exists('csrf_token')) {
    function csrf_token(): string
    {
        if (empty($_SESSION['_csrf_token'])) {
            // 32 bytes => 64 caracteres hex
            $_SESSION['_csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['_csrf_token'];
    }
}

if (!function_exists('csrf_check')) {
    function csrf_check(string $token): bool
    {
        if (empty($_SESSION['_csrf_token'])) {
            return false;
        }
        return hash_equals($_SESSION['_csrf_token'], $token);
    }
}
?>
