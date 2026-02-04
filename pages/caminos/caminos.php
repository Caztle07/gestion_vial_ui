<?php
require_once "../../config/db.php";
require_once "../../auth.php";
include "../../includes/header.php";

mysqli_set_charset($conn, "utf8");

// SOLO admin puede editar / eliminar
$puedeEditar = ($_SESSION['rol'] === 'admin');

// ===========================
// Parámetros de orden
// ===========================
$columna = $_GET["columna"] ?? "nombre";
$dir     = (isset($_GET["dir"]) && $_GET["dir"]=="DESC") ? "DESC" : "ASC";

// Whitelist de columnas ordenables (lógicas)
// estado ya no se usa; ahora tipologia
$colsValidas = ["codigo","nombre","descripcion","desde","hasta","distrito","longitud","tipologia"];
if (!in_array($columna, $colsValidas)) {
    $columna = "nombre";
}

// Columna física para el ORDER BY (con alias)
if ($columna === "distrito") {
    $columnaOrden = "d.nombre";
} else {
    $columnaOrden = "c.".$columna;
}

// ===========================
// Paginación
// ===========================
$porPagina = intval($_GET["por_pagina"] ?? 50);
$permitidos = [50, 100, 200];
if (!in_array($porPagina, $permitidos)) {
    $porPagina = 50;
}

$pagina = max(1, intval($_GET["pagina"] ?? 1)); // mínimo 1

// ===========================
// Buscador + WHERE
// ===========================
$buscar = trim($_GET["buscar"] ?? "");
$where  = "1";   // siempre verdadero

if ($buscar !== "") {
    $b = $conn->real_escape_string($buscar);
    // Usamos alias c. para caminos
    $where .= " AND (c.nombre LIKE '%$b%' OR c.codigo LIKE '%$b%')";
}

// ===========================
// Total de registros (para páginas)
// ===========================
$sqlTotal = "
    SELECT COUNT(*) AS total
    FROM caminos c
    LEFT JOIN distritos d ON d.id = c.distrito
    WHERE $where
";
$resTotal = $conn->query($sqlTotal);
$fTotal   = $resTotal->fetch_assoc();
$totalReg = intval($fTotal["total"] ?? 0);

$totalPaginas = ($totalReg > 0) ? ceil($totalReg / $porPagina) : 1;
if ($pagina > $totalPaginas) $pagina = $totalPaginas;

// Cálculos de rango de registros
$offset   = ($pagina - 1) * $porPagina;
$desdeReg = ($totalReg == 0) ? 0 : ($offset + 1);
$hastaReg = min($offset + $porPagina, $totalReg);

// ===========================
// Consulta principal paginada
// ===========================
$sqlItems = "
    SELECT 
        c.*,
        d.nombre AS distrito_nombre
    FROM caminos c
    LEFT JOIN distritos d ON d.id = c.distrito
    WHERE $where
    ORDER BY $columnaOrden $dir
    LIMIT $offset, $porPagina
";
$items = $conn->query($sqlItems);

// Para reusar en enlaces de orden/paginación
$paramBuscar    = urlencode($buscar);
$paramPorPagina = $porPagina;

// base para los enlaces de paginación
$basePag = "?buscar=".$paramBuscar."&por_pagina=".$paramPorPagina."&columna=".$columna."&dir=".$dir;
?>
<div class="container-fluid py-4">

  <div class="d-flex flex-wrap gap-3 justify-content-between align-items-center mb-3">
    <h3 class="fw-bold text-primary mb-0">
      <i class="bi bi-map"></i> Inventario de Caminos
    </h3>

    <!-- Buscador + selector de cantidad -->
    <form class="d-flex flex-wrap gap-2" method="GET">
      <input type="text" name="buscar" class="form-control"
             placeholder="Buscar por nombre o código..."
             value="<?= htmlspecialchars($buscar) ?>">

      <select name="por_pagina" class="form-select w-auto">
        <?php foreach ($permitidos as $op): ?>
          <option value="<?= $op ?>" <?= ($op==$porPagina?'selected':'') ?>>
            Mostrar <?= $op ?>
          </option>
        <?php endforeach; ?>
      </select>

      <!-- Siempre buscar desde página 1 -->
      <input type="hidden" name="pagina"  value="1">
      <input type="hidden" name="columna" value="<?= htmlspecialchars($columna) ?>">
      <input type="hidden" name="dir"     value="<?= htmlspecialchars($dir) ?>">

      <button class="btn btn-primary">
        <i class="bi bi-search"></i>
      </button>
    </form>

    <?php if ($puedeEditar): ?>
      <a href="caminos_agregar.php" class="btn btn-success">
        <i class="bi bi-plus-circle"></i> Agregar Camino
      </a>
    <?php endif; ?>
  </div>

  <div class="card shadow-sm">
    <div class="card-header fw-semibold d-flex justify-content-between align-items-center">
      <span>
        Listado de Caminos · Página <?= $pagina ?> de <?= $totalPaginas ?>
        <?php if ($totalReg > 0): ?>
          · Mostrando <?= $desdeReg ?>–<?= $hastaReg ?> de <?= $totalReg ?> registros
          · <?= $porPagina ?> por página
        <?php else: ?>
          · No hay registros
        <?php endif; ?>
      </span>
    </div>

    <div class="table-responsive mt-2">
      <table class="table table-striped align-middle mb-0 text-center">
        <thead class="table-primary">
          <tr>
            <!-- SE QUITÓ LA COLUMNA ID -->
            <th>
              <a href="?buscar=<?= $paramBuscar ?>&por_pagina=<?= $porPagina ?>&pagina=1&columna=codigo&dir=<?=($dir=='ASC'?'DESC':'ASC')?>">
                Código
              </a>
            </th>
            <th>
              <a href="?buscar=<?= $paramBuscar ?>&por_pagina=<?= $porPagina ?>&pagina=1&columna=nombre&dir=<?=($dir=='ASC'?'DESC':'ASC')?>">
                Nombre
              </a>
            </th>
            <th>Descripción</th>
            <th>Desde</th>
            <th>Hasta</th>
            <th>
              <a href="?buscar=<?= $paramBuscar ?>&por_pagina=<?= $porPagina ?>&pagina=1&columna=distrito&dir=<?=($dir=='ASC'?'DESC':'ASC')?>">
                Distrito
              </a>
            </th>
            <th>
              <a href="?buscar=<?= $paramBuscar ?>&por_pagina=<?= $porPagina ?>&pagina=1&columna=longitud&dir=<?=($dir=='ASC'?'DESC':'ASC')?>">
                Longitud
              </a>
            </th>
            <th>
              <a href="?buscar=<?= $paramBuscar ?>&por_pagina=<?= $porPagina ?>&pagina=1&columna=tipologia&dir=<?=($dir=='ASC'?'DESC':'ASC')?>">
                Tipología
              </a>
            </th>
            <?php if ($puedeEditar): ?><th>Acciones</th><?php endif; ?>
          </tr>
        </thead>

        <tbody>
        <?php if ($items->num_rows == 0): ?>
          <tr>
            <td colspan="<?= $puedeEditar ? 9 : 8 ?>" class="text-muted py-3">No hay registros.</td>
          </tr>
        <?php else: while ($i = $items->fetch_assoc()): ?>
          <tr>
            <td><?= htmlspecialchars($i["codigo"]) ?></td>
           <td><?= htmlspecialchars($i["nombre"] ?? "") ?></td>
            <td><?= htmlspecialchars($i["descripcion"] ?? "") ?></td>
            <td><?= htmlspecialchars($i["desde"]) ?></td>
            <td><?= htmlspecialchars($i["hasta"]) ?></td>
            <!-- Nombre del distrito -->
            <td><?= htmlspecialchars($i["distrito_nombre"] ?? "") ?></td>
            <td><?= htmlspecialchars($i["longitud"]) ?></td>
            <!-- NUEVO: tipologia A/B/C (o vacío) -->
            <td><?= htmlspecialchars($i["tipologia"] ?? "") ?></td>

            <?php if ($puedeEditar): ?>
              <td>
                <a href="caminos_editar.php?id=<?= $i['id'] ?>" class="btn btn-sm btn-warning">
                  <i class="bi bi-pencil"></i>
                </a>
                <a href="caminos_eliminar.php?id=<?= $i['id'] ?>" class="btn btn-sm btn-danger"
                   onclick="return confirm('¿Eliminar este camino?')">
                  <i class="bi bi-trash"></i>
                </a>
              </td>
            <?php endif; ?>
          </tr>
        <?php endwhile; endif; ?>
        </tbody>
      </table>
    </div>

    <!-- PAGINACIÓN INFERIOR -->
    <?php if ($totalPaginas > 1): ?>
      <div class="card-footer d-flex justify-content-center">
        <nav>
          <ul class="pagination mb-0">

            <!-- Primero -->
            <li class="page-item <?= ($pagina <= 1 ? 'disabled' : '') ?>">
              <a class="page-link"
                 href="<?= $pagina <= 1 ? '#' : $basePag.'&pagina=1' ?>">
                « Primero
              </a>
            </li>

            <!-- Anterior -->
            <li class="page-item <?= ($pagina <= 1 ? 'disabled' : '') ?>">
              <a class="page-link"
                 href="<?= $pagina <= 1 ? '#' : $basePag.'&pagina='.($pagina-1) ?>">
                ‹ Anterior
              </a>
            </li>

            <!-- Números -->
            <?php
              $rango = 3;
              $ini = max(1, $pagina - $rango);
              $fin = min($totalPaginas, $pagina + $rango);
              for ($p = $ini; $p <= $fin; $p++):
            ?>
              <li class="page-item <?= ($p == $pagina ? 'active' : '') ?>">
                <a class="page-link" href="<?= $basePag.'&pagina='.$p ?>"><?= $p ?></a>
              </li>
            <?php endfor; ?>

            <!-- Siguiente -->
            <li class="page-item <?= ($pagina >= $totalPaginas ? 'disabled' : '') ?>">
              <a class="page-link"
                 href="<?= $pagina >= $totalPaginas ? '#' : $basePag.'&pagina='.($pagina+1) ?>">
                Siguiente ›
              </a>
            </li>

            <!-- Último -->
            <li class="page-item <?= ($pagina >= $totalPaginas ? 'disabled' : '') ?>">
              <a class="page-link"
                 href="<?= $pagina >= $totalPaginas ? '#' : $basePag.'&pagina='.$totalPaginas ?>">
                Último »
              </a>
            </li>

          </ul>
        </nav>
      </div>
    <?php endif; ?>
    <!-- FIN PAGINACIÓN INFERIOR -->

  </div>

</div>

<?php include "../../includes/footer.php"; ?>
