<?php
require_once 'config.php';
requireLogin();

// Obtener estadísticas del día
$ventasHoy = $pdo->query("
    SELECT COUNT(*) as total, SUM(total_bs) as monto_bs, SUM(total_usd) as monto_usd 
    FROM ventas 
    WHERE DATE(created_at) = CURDATE()
")->fetch();

// Calcular ganancia del día
$gananciaHoy = $pdo->query("
    SELECT 
        SUM(dv.cantidad * (dv.precio_bs - p.costo_bs)) as ganancia_bs,
        SUM(dv.cantidad * (dv.precio_usd - (p.costo_bs / v.tasa_cambio))) as ganancia_usd
    FROM detalle_ventas dv
    JOIN productos p ON dv.producto_id = p.id
    JOIN ventas v ON dv.venta_id = v.id
    WHERE DATE(v.created_at) = CURDATE()
")->fetch();

// Calcular ganancia de la semana
$gananciaSemana = $pdo->query("
    SELECT 
        SUM(dv.cantidad * (dv.precio_bs - p.costo_bs)) as ganancia_bs,
        SUM(dv.cantidad * (dv.precio_usd - (p.costo_bs / v.tasa_cambio))) as ganancia_usd
    FROM detalle_ventas dv
    JOIN productos p ON dv.producto_id = p.id
    JOIN ventas v ON dv.venta_id = v.id
    WHERE YEARWEEK(v.created_at, 1) = YEARWEEK(CURDATE(), 1)
")->fetch();

// Calcular ganancia del mes
$gananciaMes = $pdo->query("
    SELECT 
        SUM(dv.cantidad * (dv.precio_bs - p.costo_bs)) as ganancia_bs,
        SUM(dv.cantidad * (dv.precio_usd - (p.costo_bs / v.tasa_cambio))) as ganancia_usd
    FROM detalle_ventas dv
    JOIN productos p ON dv.producto_id = p.id
    JOIN ventas v ON dv.venta_id = v.id
    WHERE YEAR(v.created_at) = YEAR(CURDATE()) AND MONTH(v.created_at) = MONTH(CURDATE())
")->fetch();

// Calcular porcentajes de ganancia
$porcentaje_ganancia_hoy = 0;
if ($ventasHoy['monto_bs'] > 0) {
    $porcentaje_ganancia_hoy = ($gananciaHoy['ganancia_bs'] / $ventasHoy['monto_bs']) * 100;
}

$ventasSemana = $pdo->query("
    SELECT SUM(total_bs) as monto_bs
    FROM ventas 
    WHERE YEARWEEK(created_at, 1) = YEARWEEK(CURDATE(), 1)
")->fetch();
$porcentaje_ganancia_semana = 0;
if ($ventasSemana['monto_bs'] > 0) {
    $porcentaje_ganancia_semana = ($gananciaSemana['ganancia_bs'] / $ventasSemana['monto_bs']) * 100;
}

$ventasMes = $pdo->query("
    SELECT SUM(total_bs) as monto_bs
    FROM ventas 
    WHERE YEAR(created_at) = YEAR(CURDATE()) AND MONTH(created_at) = MONTH(CURDATE())
")->fetch();
$porcentaje_ganancia_mes = 0;
if ($ventasMes['monto_bs'] > 0) {
    $porcentaje_ganancia_mes = ($gananciaMes['ganancia_bs'] / $ventasMes['monto_bs']) * 100;
}

// Obtener productos con stock crítico
$productosStockCritico = $pdo->query("
    SELECT nombre, stock, stock_minimo 
    FROM productos 
    WHERE stock <= stock_minimo 
    ORDER BY stock ASC 
    LIMIT 10
")->fetchAll();

// Obtener créditos vencidos (SOLO UNA CONSULTA PARA EVITAR REDUNDANCIA)
$creditosVencidos = $pdo->query("
    SELECT 
        c.*,
        cl.nombre as cliente_nombre,
        DATEDIFF(CURDATE(), c.fecha_vencimiento) as dias_vencido,
        (c.monto_usd - IFNULL((SELECT SUM(equivalente_usd) FROM abonos WHERE credito_id = c.id), 0)) as saldo_usd
    FROM creditos c
    JOIN clientes cl ON c.cliente_id = cl.id
    WHERE c.fecha_vencimiento < CURDATE() 
    AND (c.monto_usd - IFNULL((SELECT SUM(equivalente_usd) FROM abonos WHERE credito_id = c.id), 0)) > 0
    ORDER BY c.fecha_vencimiento ASC
    LIMIT 10
")->fetchAll();

$productosStockBajo = $pdo->query("
    SELECT COUNT(*) as total 
    FROM productos 
    WHERE stock <= stock_minimo
")->fetch();

$creditosActivos = $pdo->query("
    SELECT COUNT(*) as total, SUM(monto_usd) as monto_total 
    FROM creditos c
    WHERE c.monto_usd > (
        SELECT IFNULL(SUM(equivalente_usd), 0) 
        FROM abonos 
        WHERE credito_id = c.id
    )
")->fetch();

$ventasRecientes = $pdo->query("
    SELECT v.*, c.nombre as cliente_nombre, u.name as usuario_nombre
    FROM ventas v
    JOIN clientes c ON v.cliente_id = c.id
    JOIN users u ON v.user_id = u.id
    ORDER BY v.created_at DESC
    LIMIT 10
")->fetchAll();

// Obtener la tasa de cambio actual
$tasa_actual = $pdo->query("
    SELECT tasa_cambio FROM ventas 
    WHERE tasa_cambio IS NOT NULL 
    ORDER BY created_at DESC 
    LIMIT 1
")->fetchColumn();

if (!$tasa_actual) {
    $tasa_actual = 1.00;
}

// Manejo seguro de costos fijos
$costosFijos = 0;
try {
    $tableExists = $pdo->query("SHOW TABLES LIKE 'costos_fijos'")->fetch();
    if ($tableExists) {
        $costosFijos = $pdo->query("
            SELECT 
                SUM(CASE 
                    WHEN tipo = 'mensual' THEN monto
                    WHEN tipo = 'diario' THEN monto * 30
                    WHEN tipo = 'semanal' THEN monto * 4.33
                END) as total
            FROM costos_fijos
        ")->fetchColumn();
    }
} catch (PDOException $e) {
    $costosFijos = 0;
    error_log("Advertencia: Tabla costos_fijos no encontrada. Error: " . $e->getMessage());
}

$costosFijos = $costosFijos ?: 0;

// Calcular punto de equilibrio para la métrica de velocidad de ventas
$puntoEquilibrioBs = 0;
try {
    // Calcular CMV (Margen de Contribución) basado en ventas reales
    $stmt_cmv = $pdo->query("
        SELECT 
            SUM(dv.cantidad * p.costo_bs) as total_costo_ventas,
            SUM(dv.cantidad * dv.precio_bs) as total_ventas_bs
        FROM detalle_ventas dv
        JOIN productos p ON dv.producto_id = p.id
        JOIN ventas v ON dv.venta_id = v.id
        WHERE v.created_at >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
    ");
    $datos_ventas_cmv = $stmt_cmv->fetch();
    
    $cmv_index = 0;
    if ($datos_ventas_cmv['total_ventas_bs'] > 0) {
        $margen_total = $datos_ventas_cmv['total_ventas_bs'] - $datos_ventas_cmv['total_costo_ventas'];
        $cmv_index = ($margen_total / $datos_ventas_cmv['total_ventas_bs']) * 100;
    }
    
    // Si no hay ventas recientes, calcular CMV basado en inventario
    if ($cmv_index <= 0) {
        $stmt_inv = $pdo->query("
            SELECT 
                SUM(costo_bs * stock) as total_costo_inventario,
                SUM(precio_bs * stock) as total_venta_inventario
            FROM productos 
            WHERE stock > 0
        ");
        $datos_inventario_cmv = $stmt_inv->fetch();
        
        if ($datos_inventario_cmv['total_venta_inventario'] > 0) {
            $margen_total = $datos_inventario_cmv['total_venta_inventario'] - $datos_inventario_cmv['total_costo_inventario'];
            $cmv_index = ($margen_total / $datos_inventario_cmv['total_venta_inventario']) * 100;
        }
    }
    
    // Calcular punto de equilibrio
    if ($cmv_index > 0 && $costosFijos > 0) {
        $puntoEquilibrioBs = $costosFijos / ($cmv_index / 100);
    }
} catch (Exception $e) {
    $puntoEquilibrioBs = 0;
    error_log("Error calculando punto de equilibrio: " . $e->getMessage());
}

$utilidadMensual = ($gananciaMes['ganancia_bs'] ?? 0) - $costosFijos;

// Obtener ventas del mes actual para análisis dinámico
$ventasMesActual = $pdo->query("
    SELECT 
        SUM(total_bs) as total_ventas_bs,
        SUM(total_usd) as total_ventas_usd,
        COUNT(*) as total_ventas
    FROM ventas 
    WHERE YEAR(created_at) = YEAR(CURDATE()) AND MONTH(created_at) = MONTH(CURDATE())
")->fetch();

$ventasMesActual['total_ventas_bs'] = $ventasMesActual['total_ventas_bs'] ?: 0;
$ventasMesActual['total_ventas'] = $ventasMesActual['total_ventas'] ?: 0;
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard - Nine Market</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        :root {
            --bg-color: #f8f9fa;
            --text-color: #212529;
            --card-bg: white;
            --header-bg: linear-gradient(135deg, #198754 0%, #146c43 100%);
            --border-color: #dee2e6;
        }

        .modo-noche {
            --bg-color: #1a1a1a;
            --text-color: #e9ecef;
            --card-bg: #2d3748;
            --header-bg: linear-gradient(135deg, #0f5132 0%, #0a3622 100%);
            --border-color: #4a5568;
        }

        body { 
            background-color: var(--bg-color);
            color: var(--text-color);
            transition: all 0.3s ease;
        }

        .stat-card {
            border-radius: 15px;
            border: none;
            transition: transform 0.2s;
            background: var(--card-bg);
            color: var(--text-color);
        }
        .stat-card:hover { transform: translateY(-5px); }
        .stat-icon { font-size: 3rem; }
        .alert-card {
            border-left: 5px solid;
            transition: all 0.3s;
            background: var(--card-bg);
            color: var(--text-color);
        }
        .alert-card.stock { border-left-color: #ffc107; }
        .alert-card.credit { border-left-color: #dc3545; }
        .pulse-warning {
            animation: pulse-warning 2s infinite;
        }
        .pulse-danger {
            animation: pulse-danger 2s infinite;
        }
        @keyframes pulse-warning {
            0%, 100% { opacity: 1; }
            50% { opacity: 0.7; }
        }
        @keyframes pulse-danger {
            0%, 100% { 
                box-shadow: 0 0 0 0 rgba(220, 53, 69, 0.7);
            }
            70% { 
                box-shadow: 0 0 0 10px rgba(220, 53, 69, 0);
            }
            100% { 
                box-shadow: 0 0 0 0 rgba(220, 53, 69, 0);
            }
        }
        .margin-badge {
            font-size: 0.9rem;
            padding: 8px 12px;
            border-radius: 20px;
        }
        .margin-excellent { background: linear-gradient(135deg, #198754 0%, #146c43 100%); color: white; }
        .margin-good { background: linear-gradient(135deg, #20c997 0%, #198754 100%); color: white; }
        .margin-fair { background: linear-gradient(135deg, #ffc107 0%, #fd7e14 100%); color: black; }
        .margin-poor { background: linear-gradient(135deg, #fd7e14 0%, #dc3545 100%); color: white; }
        
        .access-card {
            border: none;
            border-radius: 15px;
            transition: all 0.3s ease;
            height: 100%;
            text-decoration: none;
            color: inherit;
            background: var(--card-bg);
        }
        .access-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 25px rgba(0,0,0,0.15);
            text-decoration: none;
            color: inherit;
        }
        .access-icon {
            font-size: 3rem;
            margin-bottom: 15px;
        }
        .module-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }

        /* Modo noche específico */
        .modo-noche .card {
            background: var(--card-bg);
            color: var(--text-color);
            border-color: var(--border-color);
        }
        .modo-noche .table {
            color: var(--text-color);
        }
        .modo-noche .table-hover tbody tr:hover {
            background-color: rgba(255,255,255,0.1);
        }
        .modo-noche .text-muted {
            color: #a0aec0 !important;
        }
        .modo-noche .bg-light {
            background-color: #4a5568 !important;
        }

        /* Botón modo noche */
        .modo-toggle {
            background: none;
            border: 2px solid white;
            color: white;
            border-radius: 20px;
            padding: 5px 15px;
            cursor: pointer;
            transition: all 0.3s ease;
            margin-right: 10px;
        }
        .modo-toggle:hover {
            background: rgba(255,255,255,0.1);
        }
        .modo-noche .modo-toggle {
            border-color: #e9ecef;
            color: #e9ecef;
        }
    </style>
</head>
<body>
    <nav class="navbar navbar-dark navbar-expand-lg mb-4" style="background: var(--header-bg) !important;">
        <div class="container-fluid">
            <?php if (basename($_SERVER['PHP_SELF']) != 'index.php'): ?>
                <a class="navbar-brand" href="index.php">
                    ← Volver al Dashboard
                </a>
            <?php else: ?>
                <span class="navbar-brand">🏪 Nine Market</span>
            <?php endif; ?>
            
            <div class="d-flex align-items-center">
                <button class="modo-toggle" id="modoNocheBtn">
                    🌙 Modo Noche
                </button>
                <span class="text-white me-3">👤 <?= htmlspecialchars($_SESSION['user_name'] ?? 'Usuario') ?></span>
                <a href="logout.php" class="btn btn-outline-light btn-sm">Salir</a>
            </div>
        </div>
    </nav>

    <div class="container-fluid">
        <h2 class="mb-4">📊 Dashboard Principal</h2>

        <!-- SECCIÓN DE ACCESOS RÁPIDOS -->
        <div class="module-grid">
            <a href="pos.php" class="access-card">
                <div class="card shadow-sm h-100 border-success">
                    <div class="card-body text-center text-success">
                        <div class="access-icon">🛒</div>
                        <h5>Punto de Venta</h5>
                        <p class="text-muted">Ventas rápidas y facturación</p>
                    </div>
                </div>
            </a>

            <a href="productos.php" class="access-card">
                <div class="card shadow-sm h-100 border-primary">
                    <div class="card-body text-center text-primary">
                        <div class="access-icon">📦</div>
                        <h5>Gestión de Productos</h5>
                        <p class="text-muted">Inventario y precios</p>
                    </div>
                </div>
            </a>

            <a href="clientes.php" class="access-card">
                <div class="card shadow-sm h-100 border-info">
                    <div class="card-body text-center text-info">
                        <div class="access-icon">👥</div>
                        <h5>Gestión de Clientes</h5>
                        <p class="text-muted">Clientes y clasificaciones</p>
                    </div>
                </div>
            </a>

            <a href="creditos.php" class="access-card">
                <div class="card shadow-sm h-100 border-warning">
                    <div class="card-body text-center text-warning">
                        <div class="access-icon">💳</div>
                        <h5>Control de Créditos</h5>
                        <p class="text-muted">Créditos y cobranzas</p>
                    </div>
                </div>
            </a>

            <a href="punto_equilibrio.php" class="access-card">
                <div class="card shadow-sm h-100 border-dark">
                    <div class="card-body text-center text-dark">
                        <div class="access-icon">📈</div>
                        <h5>Punto de Equilibrio</h5>
                        <p class="text-muted">Análisis de rentabilidad</p>
                    </div>
                </div>
            </a>

            <?php if (isAdmin()): ?>
            <a href="refacturacion.php" class="access-card">
                <div class="card shadow-sm h-100 border-secondary">
                    <div class="card-body text-center text-secondary">
                        <div class="access-icon">🔄</div>
                        <h5>Refacturación</h5>
                        <p class="text-muted">Ajustes de facturación</p>
                    </div>
                </div>
            </a>

            <a href="configuracion.php" class="access-card">
                <div class="card shadow-sm h-100 border-secondary">
                    <div class="card-body text-center text-secondary">
                        <div class="access-icon">⚙️</div>
                        <h5>Configuración</h5>
                        <p class="text-muted">Ajustes del sistema</p>
                    </div>
                </div>
            </a>

            <a href="backup_db.php" class="access-card">
                <div class="card shadow-sm h-100 border-secondary">
                    <div class="card-body text-center text-secondary">
                        <div class="access-icon">💾</div>
                        <h5>Backup Base de Datos</h5>
                        <p class="text-muted">Respaldo y restauración</p>
                    </div>
                </div>
            </a>
            <?php endif; ?>
        </div>

        <!-- Alerta para crear tabla costos_fijos -->
        <?php 
        $tableExists = $pdo->query("SHOW TABLES LIKE 'costos_fijos'")->fetch();
        if (!$tableExists): ?>
        <div class="alert alert-warning alert-dismissible fade show">
            <strong>⚠️ Configuración Requerida:</strong> La tabla de costos fijos no existe. 
            <a href="#costos-fijos-setup" data-bs-toggle="collapse" class="alert-link">
                Haz clic aquí para crearla
            </a>
            <div id="costos-fijos-setup" class="collapse mt-3">
                <p>Ejecuta este SQL en tu base de datos:</p>
                <pre class="bg-dark text-light p-3 rounded">
CREATE TABLE `costos_fijos` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `nombre` VARCHAR(255) NOT NULL,
    `monto` DECIMAL(12,2) NOT NULL,
    `tipo` ENUM('mensual', 'semanal', 'diario') NOT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);</pre>
                <p>O ve al módulo de <a href="punto_equilibrio.php" class="alert-link">Punto de Equilibrio</a> para crearla automáticamente.</p>
            </div>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php endif; ?>

        <!-- Tarjetas de estadísticas principales CORREGIDAS -->
        <div class="row g-4 mb-4">
            <div class="col-md-3">
                <div class="card stat-card shadow-sm">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <h6 class="text-muted mb-1">Ventas Hoy</h6>
                                <h3 class="mb-0"><?= $ventasHoy['total'] ?? 0 ?></h3>
                                <small class="text-primary">
                                    <?= formatMoney($ventasHoy['monto_bs'] ?? 0) ?>
                                </small>
                            </div>
                            <div class="stat-icon text-primary">💰</div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-md-3">
                <div class="card stat-card shadow-sm">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <h6 class="text-muted mb-1">Stock Bajo</h6>
                                <h3 class="mb-0"><?= $productosStockBajo['total'] ?? 0 ?></h3>
                                <small class="text-warning">Productos críticos</small>
                            </div>
                            <div class="stat-icon text-warning">📦</div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-md-3">
                <div class="card stat-card shadow-sm">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <h6 class="text-muted mb-1">Créditos Activos</h6>
                                <h3 class="mb-0"><?= $creditosActivos['total'] ?? 0 ?></h3>
                                <small class="text-info">
                                    $<?= number_format($creditosActivos['monto_total'] ?? 0, 2) ?>
                                </small>
                            </div>
                            <div class="stat-icon text-info">💳</div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-md-3">
                <div class="card stat-card shadow-sm">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <h6 class="text-muted mb-1">Ganancia Hoy</h6>
                                <h3 class="mb-0"><?= formatMoney($gananciaHoy['ganancia_bs'] ?? 0, '') ?></h3>
                                <small class="text-success">
                                    <?= number_format($porcentaje_ganancia_hoy, 1) ?>% margen
                                </small>
                            </div>
                            <div class="stat-icon text-success">📊</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Segunda fila - GANANCIAS POR PERÍODO -->
        <div class="row g-4 mb-4">
            <div class="col-md-6">
                <div class="card stat-card shadow-sm bg-info text-white">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <h6 class="mb-1 opacity-75">📈 Ganancia Semanal</h6>
                                <h3 class="mb-0"><?= formatMoney($gananciaSemana['ganancia_bs'] ?? 0, 'Bs') ?></h3>
                                <small class="opacity-75">
                                    $<?= number_format($gananciaSemana['ganancia_usd'] ?? 0, 2) ?>
                                </small>
                            </div>
                            <div class="stat-icon">🗓️</div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-md-6">
                <div class="card stat-card shadow-sm bg-warning text-dark">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <h6 class="mb-1">📊 Ganancia Mensual</h6>
                                <h3 class="mb-0"><?= formatMoney($gananciaMes['ganancia_bs'] ?? 0, 'Bs') ?></h3>
                                <small>
                                    $<?= number_format($gananciaMes['ganancia_usd'] ?? 0, 2) ?>
                                </small>
                            </div>
                            <div class="stat-icon">💰</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Tercera fila - PORCENTAJES DE GANANCIA -->
        <div class="row g-4 mb-4">
            <div class="col-md-4">
                <div class="card stat-card shadow-sm bg-primary text-white">
                    <div class="card-body text-center">
                        <h6 class="mb-1 opacity-75">📊 Margen Hoy</h6>
                        <h3 class="mb-2"><?= number_format($porcentaje_ganancia_hoy, 1) ?>%</h3>
                        <?php 
                        $margin_class_hoy = 
                            $porcentaje_ganancia_hoy >= 30 ? 'margin-excellent' :
                            ($porcentaje_ganancia_hoy >= 20 ? 'margin-good' :
                            ($porcentaje_ganancia_hoy >= 10 ? 'margin-fair' : 'margin-poor'));
                        ?>
                        <span class="margin-badge <?= $margin_class_hoy ?>">
                            <?= 
                            $porcentaje_ganancia_hoy >= 30 ? 'Excelente' :
                            ($porcentaje_ganancia_hoy >= 20 ? 'Bueno' :
                            ($porcentaje_ganancia_hoy >= 10 ? 'Regular' : 'Bajo'))
                            ?>
                        </span>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card stat-card shadow-sm bg-info text-white">
                    <div class="card-body text-center">
                        <h6 class="mb-1 opacity-75">📈 Margen Semanal</h6>
                        <h3 class="mb-2"><?= number_format($porcentaje_ganancia_semana, 1) ?>%</h3>
                        <?php 
                        $margin_class_semana = 
                            $porcentaje_ganancia_semana >= 30 ? 'margin-excellent' :
                            ($porcentaje_ganancia_semana >= 20 ? 'margin-good' :
                            ($porcentaje_ganancia_semana >= 10 ? 'margin-fair' : 'margin-poor'));
                        ?>
                        <span class="margin-badge <?= $margin_class_semana ?>">
                            <?= 
                            $porcentaje_ganancia_semana >= 30 ? 'Excelente' :
                            ($porcentaje_ganancia_semana >= 20 ? 'Bueno' :
                            ($porcentaje_ganancia_semana >= 10 ? 'Regular' : 'Bajo'))
                            ?>
                        </span>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card stat-card shadow-sm bg-success text-white">
                    <div class="card-body text-center">
                        <h6 class="mb-1 opacity-75">💰 Margen Mensual</h6>
                        <h3 class="mb-2"><?= number_format($porcentaje_ganancia_mes, 1) ?>%</h3>
                        <?php 
                        $margin_class_mes = 
                            $porcentaje_ganancia_mes >= 30 ? 'margin-excellent' :
                            ($porcentaje_ganancia_mes >= 20 ? 'margin-good' :
                            ($porcentaje_ganancia_mes >= 10 ? 'margin-fair' : 'margin-poor'));
                        ?>
                        <span class="margin-badge <?= $margin_class_mes ?>">
                            <?= 
                            $porcentaje_ganancia_mes >= 30 ? 'Excelente' :
                            ($porcentaje_ganancia_mes >= 20 ? 'Bueno' :
                            ($porcentaje_ganancia_mes >= 10 ? 'Regular' : 'Bajo'))
                            ?>
                        </span>
                    </div>
                </div>
            </div>
        </div>

        <!-- Cuarta fila - ALERTAS CRÍTICAS (SIN REDUNDANCIA) -->
        <div class="row g-4 mb-4">
            <!-- Alerta Stock Crítico -->
            <div class="col-lg-6">
                <div class="card shadow-sm border-warning alert-card stock <?= !empty($productosStockCritico) ? 'pulse-warning' : '' ?>">
                    <div class="card-header bg-warning text-dark d-flex justify-content-between align-items-center">
                        <h6 class="mb-0">⚠️ Stock Crítico</h6>
                        <span class="badge bg-danger"><?= count($productosStockCritico) ?></span>
                    </div>
                    <div class="card-body">
                        <?php if (empty($productosStockCritico)): ?>
                            <div class="text-center text-muted py-3">
                                <p class="fs-1">✅</p>
                                <p>Todo el stock está bajo control</p>
                            </div>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table class="table table-sm table-hover">
                                    <thead>
                                        <tr>
                                            <th>Producto</th>
                                            <th>Stock Actual</th>
                                            <th>Mínimo</th>
                                            <th>Acción</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($productosStockCritico as $producto): 
                                            $nivel_critico = $producto['stock'] == 0 ? 'danger' : 
                                                           ($producto['stock'] <= 2 ? 'warning' : 'info');
                                        ?>
                                        <tr>
                                            <td><?= htmlspecialchars($producto['nombre']) ?></td>
                                            <td>
                                                <span class="badge bg-<?= $nivel_critico ?>">
                                                    <?= $producto['stock'] ?>
                                                </span>
                                            </td>
                                            <td><?= $producto['stock_minimo'] ?></td>
                                            <td>
                                                <a href="productos.php" class="btn btn-sm btn-outline-primary">
                                                    Reabastecer
                                                </a>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                            <div class="text-center mt-2">
                                <a href="productos.php" class="btn btn-warning btn-sm">
                                    Ver Todos los Productos
                                </a>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Alerta Créditos Vencidos (ÚNICA SECCIÓN) -->
            <div class="col-lg-6">
                <div class="card shadow-sm border-danger alert-card credit <?= !empty($creditosVencidos) ? 'pulse-danger' : '' ?>">
                    <div class="card-header bg-danger text-white d-flex justify-content-between align-items-center">
                        <h6 class="mb-0">💳 Créditos Vencidos</h6>
                        <span class="badge bg-dark"><?= count($creditosVencidos) ?></span>
                    </div>
                    <div class="card-body">
                        <?php if (empty($creditosVencidos)): ?>
                            <div class="text-center text-muted py-3">
                                <p class="fs-1">✅</p>
                                <p>No hay créditos vencidos</p>
                            </div>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table class="table table-sm table-hover">
                                    <thead>
                                        <tr>
                                            <th>Cliente</th>
                                            <th>Días Vencido</th>
                                            <th>Saldo USD</th>
                                            <th>Saldo Bs</th>
                                            <th>Acción</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($creditosVencidos as $credito): 
                                            $dias_color = $credito['dias_vencido'] > 30 ? 'danger' : 
                                                         ($credito['dias_vencido'] > 15 ? 'warning' : 'secondary');
                                        ?>
                                        <tr>
                                            <td><?= htmlspecialchars($credito['cliente_nombre']) ?></td>
                                            <td>
                                                <span class="badge bg-<?= $dias_color ?>">+<?= $credito['dias_vencido'] ?>d</span>
                                            </td>
                                            <td class="fw-bold">$<?= number_format($credito['saldo_usd'], 2) ?></td>
                                            <td><?= formatMoney($credito['saldo_usd'] * $tasa_actual) ?></td>
                                            <td>
                                                <a href="creditos.php" class="btn btn-sm btn-outline-warning">
                                                    Cobrar
                                                </a>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                            <div class="text-center mt-2">
                                <a href="creditos.php" class="btn btn-danger btn-sm">
                                    Gestionar Créditos
                                </a>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- Quinta fila - ANÁLISIS DE RENTABILIDAD -->
        <div class="row g-4 mb-4">
            <div class="col-md-6">
                <div class="card shadow-sm">
                    <div class="card-body">
                        <h6 class="text-muted mb-3">📈 Rentabilidad vs Costos Fijos</h6>
                        <?php 
                        $gananciaMensual = $gananciaMes['ganancia_bs'] ?? 0;
                        $utilidadNeta = $gananciaMensual - $costosFijos;
                        $margenSeguridad = $costosFijos > 0 ? ($gananciaMensual / $costosFijos) * 100 : 0;
                        ?>
                        <div class="row text-center">
                            <div class="col-4">
                                <small class="text-muted d-block">Costos Fijos</small>
                                <strong class="text-danger">Bs <?= number_format($costosFijos, 2) ?></strong>
                            </div>
                            <div class="col-4">
                                <small class="text-muted d-block">Utilidad Neta</small>
                                <strong class="<?= $utilidadNeta >= 0 ? 'text-success' : 'text-danger' ?>">
                                    Bs <?= number_format($utilidadNeta, 2) ?>
                                </strong>
                            </div>
                            <div class="col-4">
                                <small class="text-muted d-block">Margen Seguridad</small>
                                <strong class="<?= $margenSeguridad >= 100 ? 'text-success' : 'text-warning' ?>">
                                    <?= number_format($margenSeguridad, 1) ?>%
                                </strong>
                            </div>
                        </div>
                        <div class="progress mt-3" style="height: 20px;">
                            <div class="progress-bar bg-danger" style="width: <?= min(100, ($costosFijos / max(1, $gananciaMensual)) * 100) ?>%">
                                Costos
                            </div>
                            <div class="progress-bar bg-success" style="width: <?= max(0, ($utilidadNeta / max(1, $gananciaMensual)) * 100) ?>%">
                                Utilidad
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-md-6">
                <div class="card shadow-sm bg-light">
                    <div class="card-body text-center">
                        <h6 class="text-muted mb-3">⚡ Estado Financiero</h6>
                        <?php if ($utilidadNeta > 0): ?>
                            <div class="text-success">
                                <div class="fs-1">✅</div>
                                <strong>RENTABLE</strong>
                                <div class="mt-2">El negocio genera utilidades</div>
                            </div>
                        <?php else: ?>
                            <div class="text-warning">
                                <div class="fs-1">⚠️</div>
                                <strong>EN PÉRDIDAS</strong>
                                <div class="mt-2">Se necesitan más ventas</div>
                            </div>
                        <?php endif; ?>
                        <a href="punto_equilibrio.php" class="btn btn-outline-primary btn-sm mt-3">
                            📊 Ver Análisis Detallado
                        </a>
                    </div>
                </div>
            </div>
        </div>

        <!-- Sexta fila - MÉTRICAS ADICIONALES -->
        <div class="row g-4 mb-4">
            <div class="col-md-4">
                <div class="card shadow-sm">
                    <div class="card-body">
                        <h6 class="text-muted mb-3">💡 Velocidad de Ventas</h6>
                        <?php 
                        $diasTranscurridos = date('j');
                        $diasTotales = date('t');
                        $diasRestantes = $diasTotales - $diasTranscurridos;
                        
                        if ($puntoEquilibrioBs > 0 && $diasRestantes > 0) {
                            $ventasNecesariasPorDia = ($puntoEquilibrioBs - $ventasMesActual['total_ventas_bs']) / $diasRestantes;
                            $ventasNecesariasPorDia = max(0, $ventasNecesariasPorDia); // No mostrar valores negativos
                        } else {
                            $ventasNecesariasPorDia = 0;
                        }
                        ?>
                        <div class="text-center">
                            <?php if ($ventasNecesariasPorDia > 0): ?>
                                <h4 class="text-warning">Bs <?= number_format($ventasNecesariasPorDia, 2) ?></h4>
                                <small class="text-muted">por día para alcanzar equilibrio</small>
                                <div class="mt-2">
                                    <small class="text-info">
                                        <?= $diasRestantes ?> días restantes
                                    </small>
                                </div>
                            <?php else: ?>
                                <h4 class="text-success">✅ Alcanzado</h4>
                                <small class="text-muted">Meta de equilibrio mensual</small>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-md-4">
                <div class="card shadow-sm">
                    <div class="card-body">
                        <h6 class="text-muted mb-3">📊 Tasa de Cambio Actual</h6>
                        <div class="d-flex align-items-center">
                            <div class="fs-2 text-success me-3">💵</div>
                            <div>
                                <div class="fs-4 fw-bold text-success">
                                    <?= number_format($tasa_actual, 2) ?> Bs/$
                                </div>
                                <small class="text-muted">Última venta registrada</small>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-md-4">
                <div class="card shadow-sm bg-primary text-white">
                    <div class="card-body text-center">
                        <h6 class="mb-3 opacity-75">⚡ Acción Rápida</h6>
                        <a href="pos.php" class="btn btn-light btn-lg w-100">
                            🛒 Abrir Punto de Venta
                        </a>
                    </div>
                </div>
            </div>
        </div>

        <!-- Ventas recientes -->
        <div class="card shadow-sm">
            <div class="card-header bg-white">
                <h5 class="mb-0">📋 Ventas Recientes</h5>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-hover">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>Fecha</th>
                                <th>Cliente</th>
                                <th>Usuario</th>
                                <th>Total Bs</th>
                                <th>Total USD</th>
                                <th>Método</th>
                                <th>Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($ventasRecientes)): ?>
                                <tr>
                                    <td colspan="8" class="text-center text-muted py-4">
                                        No hay ventas registradas
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($ventasRecientes as $venta): ?>
                                <tr>
                                    <td><?= $venta['id'] ?></td>
                                    <td><?= date('d/m/Y H:i', strtotime($venta['created_at'])) ?></td>
                                    <td><?= htmlspecialchars($venta['cliente_nombre']) ?></td>
                                    <td><?= htmlspecialchars($venta['usuario_nombre']) ?></td>
                                    <td><?= formatMoney($venta['total_bs']) ?></td>
                                    <td>$<?= number_format($venta['total_usd'], 2) ?></td>
                                    <td>
                                        <?php if ($venta['metodo_pago'] === 'credito'): ?>
                                            <span class="badge bg-warning">💳 Crédito</span>
                                        <?php else: ?>
                                            <span class="badge bg-success">💵 Efectivo</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <a href="factura_pdf.php?id=<?= $venta['id'] ?>" 
                                           class="btn btn-sm btn-outline-primary" 
                                           target="_blank">
                                            📄 PDF
                                        </a>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Modo Noche
        const modoNocheBtn = document.getElementById('modoNocheBtn');
        const body = document.body;
        
        // Verificar preferencia guardada
        if (localStorage.getItem('modoNoche') === 'true') {
            body.classList.add('modo-noche');
            modoNocheBtn.textContent = '☀️ Modo Día';
        }
        
        modoNocheBtn.addEventListener('click', function() {
            body.classList.toggle('modo-noche');
            
            if (body.classList.contains('modo-noche')) {
                localStorage.setItem('modoNoche', 'true');
                modoNocheBtn.textContent = '☀️ Modo Día';
            } else {
                localStorage.setItem('modoNoche', 'false');
                modoNocheBtn.textContent = '🌙 Modo Noche';
            }
        });

        // Auto-actualizar cada 30 segundos
        setInterval(() => {
            // Recargar solo si el usuario está en el dashboard
            if (window.location.pathname.includes('index.php')) {
                window.location.reload();
            }
        }, 30000);
    </script>
</body>
</html>