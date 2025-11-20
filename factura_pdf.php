<?php
require_once 'config.php';
requireLogin();

$venta_id = $_GET['id'] ?? 0;

// Obtener datos de la venta
$stmt = $pdo->prepare("
    SELECT v.*, c.nombre as cliente_nombre, c.telefono, c.direccion, u.name as usuario_nombre
    FROM ventas v
    JOIN clientes c ON v.cliente_id = c.id
    JOIN users u ON v.user_id = u.id
    WHERE v.id = ?
");
$stmt->execute([$venta_id]);
$venta = $stmt->fetch();

if (!$venta) {
    die('Venta no encontrada');
}

// Obtener detalles
$stmt = $pdo->prepare("
    SELECT dv.*, p.nombre as producto_nombre
    FROM detalle_ventas dv
    JOIN productos p ON dv.producto_id = p.id
    WHERE dv.venta_id = ?
");
$stmt->execute([$venta_id]);
$detalles = $stmt->fetchAll();

$config = getConfiguracion($pdo);

// Calcular subtotal e IVA
$subtotal_sin_iva = $venta['total_bs'] / 1.16;
$iva_monto = $venta['total_bs'] - $subtotal_sin_iva;
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Factura #<?= $venta['id'] ?> - <?= htmlspecialchars($config['nombre_negocio']) ?></title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { 
            font-family: Arial, sans-serif; 
            padding: 15px;
            font-size: 11px;
        }
        .factura {
            max-width: 550px; /* Reducido de 800px a 550px */
            margin: 0 auto;
            border: 2px solid #198754;
        }
        .header {
            background: linear-gradient(135deg, #198754 0%, #146c43 100%);
            color: white;
            padding: 15px;
            text-align: center;
        }
        .header h1 { font-size: 22px; margin-bottom: 3px; }
        .header p { margin: 2px 0; font-size: 10px; }
        .info-section {
            display: flex;
            justify-content: space-between;
            padding: 15px;
            border-bottom: 1px solid #ddd;
        }
        .info-box {
            flex: 1;
        }
        .info-box h3 {
            color: #198754;
            font-size: 12px;
            margin-bottom: 8px;
            border-bottom: 2px solid #198754;
            padding-bottom: 3px;
        }
        .info-box p {
            margin: 3px 0;
            line-height: 1.3;
            font-size: 10px;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin: 15px 0;
            font-size: 10px;
        }
        th {
            background-color: #198754;
            color: white;
            padding: 8px;
            text-align: left;
            font-size: 10px;
        }
        td {
            padding: 6px 8px;
            border-bottom: 1px solid #eee;
        }
        tr:hover td { background-color: #f8f9fa; }
        .totales {
            padding: 15px;
            background-color: #f8f9fa;
        }
        .totales-grid {
            display: grid;
            grid-template-columns: 1fr 150px; /* Reducido de 200px */
            gap: 8px;
            max-width: 350px; /* Reducido de 400px */
            margin-left: auto;
        }
        .totales-label {
            text-align: right;
            font-weight: bold;
            font-size: 10px;
        }
        .totales-valor {
            text-align: right;
            font-size: 10px;
        }
        .total-final {
            font-size: 14px;
            color: #198754;
            font-weight: bold;
            border-top: 2px solid #198754;
            padding-top: 8px;
            margin-top: 8px;
        }
        .footer {
            padding: 15px;
            text-align: center;
            border-top: 2px solid #198754;
            background-color: #f8f9fa;
            font-size: 9px;
            color: #666;
        }
        .badge {
            display: inline-block;
            padding: 3px 6px;
            border-radius: 3px;
            font-size: 9px;
            font-weight: bold;
        }
        .badge-efectivo {
            background-color: #198754;
            color: white;
        }
        .badge-credito {
            background-color: #ffc107;
            color: #000;
        }
        @media print {
            body { padding: 0; }
            .no-print { display: none; }
            .factura { 
                max-width: 100%;
                border: none;
            }
        }

        /* Estilos para impresión más compacta */
        @media print {
            body { 
                font-size: 9px;
                padding: 5px;
            }
            .header { padding: 10px; }
            .header h1 { font-size: 18px; }
            .info-section { padding: 10px; }
            table { margin: 10px 0; }
            th, td { padding: 4px 6px; }
            .totales { padding: 10px; }
        }

        /* Ajustes para móviles */
        @media screen and (max-width: 600px) {
            .factura {
                max-width: 100%;
                margin: 0 5px;
            }
            .info-section {
                flex-direction: column;
                gap: 10px;
            }
            .totales-grid {
                max-width: 100%;
                grid-template-columns: 1fr 120px;
            }
        }
    </style>
</head>
<body>
    <div class="no-print" style="text-align: center; margin-bottom: 15px;">
        <button onclick="window.print()" style="padding: 8px 15px; background: #198754; color: white; border: none; border-radius: 5px; cursor: pointer; font-size: 11px;">
            🖨️ Imprimir Factura
        </button>
        <button onclick="window.close()" style="padding: 8px 15px; background: #6c757d; color: white; border: none; border-radius: 5px; cursor: pointer; margin-left: 10px; font-size: 11px;">
            ✖️ Cerrar
        </button>
    </div>

    <div class="factura">
        <div class="header">
            <h1><?= htmlspecialchars($config['nombre_negocio']) ?></h1>
            <p>Sistema de Facturación</p>
            <p style="font-size: 8px; margin-top: 3px;">Nine Market - Gestión Comercial</p>
        </div>

        <div class="info-section">
            <div class="info-box">
                <h3>📄 Información de Factura</h3>
                <p><strong>Factura N°:</strong> <?= str_pad($venta['id'], 6, '0', STR_PAD_LEFT) ?></p>
                <p><strong>Fecha:</strong> <?= date('d/m/Y H:i', strtotime($venta['created_at'])) ?></p>
                <p><strong>Cajero:</strong> <?= htmlspecialchars($venta['usuario_nombre']) ?></p>
                <p><strong>Método de Pago:</strong> 
                    <span class="badge badge-<?= $venta['metodo_pago'] ?>">
                        <?= $venta['metodo_pago'] === 'credito' ? '💳 Crédito' : '💵 Efectivo' ?>
                    </span>
                </p>
            </div>

            <div class="info-box">
                <h3>👤 Datos del Cliente</h3>
                <p><strong>Nombre:</strong> <?= htmlspecialchars($venta['cliente_nombre']) ?></p>
                <?php if ($venta['telefono']): ?>
                <p><strong>Teléfono:</strong> <?= htmlspecialchars($venta['telefono']) ?></p>
                <?php endif; ?>
                <?php if ($venta['direccion']): ?>
                <p><strong>Dirección:</strong> <?= htmlspecialchars($venta['direccion']) ?></p>
                <?php endif; ?>
            </div>
        </div>

        <div style="padding: 0 15px;">
            <table>
                <thead>
                    <tr>
                        <th style="width: 30px;">#</th>
                        <th>Producto</th>
                        <th style="width: 40px; text-align: center;">Cant.</th>
                        <th style="width: 70px; text-align: right;">Precio Unit.</th>
                        <th style="width: 80px; text-align: right;">Subtotal</th>
                    </tr>
                </thead>
                <tbody>
                    <?php $item_num = 1; foreach ($detalles as $detalle): ?>
                    <tr>
                        <td><?= $item_num++ ?></td>
                        <td><?= htmlspecialchars($detalle['producto_nombre']) ?></td>
                        <td style="text-align: center;"><?= $detalle['cantidad'] ?></td>
                        <td style="text-align: right;">Bs <?= number_format($detalle['precio_bs'], 2) ?></td>
                        <td style="text-align: right;">Bs <?= number_format($detalle['subtotal_bs'], 2) ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <div class="totales">
            <div class="totales-grid">
                <div class="totales-label">Subtotal (sin IVA):</div>
                <div class="totales-valor">Bs <?= number_format($subtotal_sin_iva, 2) ?></div>
                
                <div class="totales-label">IVA (<?= $config['iva'] ?>%):</div>
                <div class="totales-valor">Bs <?= number_format($iva_monto, 2) ?></div>
                
                <div class="totales-label total-final">TOTAL Bs:</div>
                <div class="totales-valor total-final">Bs <?= number_format($venta['total_bs'], 2) ?></div>
                
                <div class="totales-label" style="font-size: 11px;">TOTAL USD:</div>
                <div class="totales-valor" style="font-size: 11px;">$<?= number_format($venta['total_usd'], 2) ?></div>
            </div>
            
            <div style="text-align: right; margin-top: 8px; font-size: 9px; color: #666;">
                Tasa de cambio: <?= number_format($venta['tasa_cambio'], 2) ?> Bs/$
            </div>

            <?php if ($venta['metodo_pago'] === 'credito'): 
                $stmt = $pdo->prepare("SELECT fecha_vencimiento FROM creditos WHERE venta_id = ?");
                $stmt->execute([$venta_id]);
                $credito = $stmt->fetch();
            ?>
            <div style="margin-top: 12px; padding: 8px; background: #fff3cd; border-left: 4px solid #ffc107; font-size: 9px;">
                <strong>⚠️ VENTA A CRÉDITO</strong><br>
                <small>Fecha de vencimiento: <?= date('d/m/Y', strtotime($credito['fecha_vencimiento'])) ?></small>
            </div>
            <?php endif; ?>
        </div>

        <div class="footer">
            <p><strong>¡Gracias por su compra!</strong></p>
            <p style="margin-top: 8px;">
                Este documento es una factura válida generada por Nine Market<br>
                Precios incluyen IVA (<?= $config['iva'] ?>%) | Sistema offline-first para PYMEs
            </p>
            <p style="margin-top: 8px; font-size: 8px;">
                Factura generada el <?= date('d/m/Y H:i:s') ?>
            </p>
        </div>
    </div>

    <script>
        // Auto-imprimir si se solicita
        if (window.location.search.includes('autoprint=true')) {
            window.print();
        }

        // Cerrar automáticamente después de imprimir
        window.onafterprint = function() {
            setTimeout(function() {
                if (window.location.search.includes('autoclose=true')) {
                    window.close();
                }
            }, 500);
        };
    </script>
</body>
</html>