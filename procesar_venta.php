<?php
require_once 'config.php';
requireLogin();

header('Content-Type: application/json');

// Validar método
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['success' => false, 'message' => 'Método no permitido'], 405);
}

try {
    $data = json_decode(file_get_contents('php://input'), true);
    
    if (!$data || !isset($data['items']) || empty($data['items'])) {
        throw new Exception('Datos inválidos o carrito vacío');
    }
    
    $items = $data['items'];
    $cliente_id = intval($data['cliente_id']);
    $metodo_pago = $data['metodo_pago'];
    $total_bs = floatval($data['total_bs']);
    $total_usd = floatval($data['total_usd']);
    $tasa_cambio = floatval($data['tasa_cambio']);
    $user_id = $_SESSION['user_id'];
    
    // Validaciones básicas
    if ($cliente_id <= 0) {
        throw new Exception('Cliente inválido');
    }
    
    if ($total_bs <= 0) {
        throw new Exception('Monto total inválido');
    }
    
    // Iniciar transacción
    $pdo->beginTransaction();
    
    // Verificar stock de todos los productos y bloquear registros
    foreach ($items as $item) {
        $producto_id = intval($item['id']);
        $cantidad = intval($item['cantidad']);
        
        if ($cantidad <= 0) {
            throw new Exception('Cantidad inválida para ' . $item['nombre']);
        }
        
        $stmt = $pdo->prepare("SELECT stock, nombre FROM productos WHERE id = ? FOR UPDATE");
        $stmt->execute([$producto_id]);
        $producto = $stmt->fetch();
        
        if (!$producto) {
            throw new Exception('Producto no encontrado: ' . $item['nombre']);
        }
        
        if ($producto['stock'] < $cantidad) {
            throw new Exception('Stock insuficiente para ' . $producto['nombre'] . '. Disponible: ' . $producto['stock']);
        }
    }
    
    // Crear venta
    $stmt = $pdo->prepare("
        INSERT INTO ventas (user_id, cliente_id, total_bs, total_usd, metodo_pago, tasa_cambio) 
        VALUES (?, ?, ?, ?, ?, ?)
    ");
    $stmt->execute([$user_id, $cliente_id, $total_bs, $total_usd, $metodo_pago, $tasa_cambio]);
    $venta_id = $pdo->lastInsertId();
    
    // Crear detalles de venta y actualizar stock
    foreach ($items as $item) {
        $producto_id = intval($item['id']);
        $cantidad = intval($item['cantidad']);
        $precio_bs = floatval($item['precio_bs']);
        $precio_usd = floatval($item['precio_usd']);
        
        // Insertar detalle
        $stmt = $pdo->prepare("
            INSERT INTO detalle_ventas (venta_id, producto_id, cantidad, precio_bs, precio_usd, subtotal_bs, subtotal_usd) 
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ");
        $subtotal_bs = $cantidad * $precio_bs;
        $subtotal_usd = $cantidad * $precio_usd;
        $stmt->execute([
            $venta_id, 
            $producto_id, 
            $cantidad, 
            $precio_bs, 
            $precio_usd, 
            $subtotal_bs, 
            $subtotal_usd
        ]);
        
        // Actualizar stock de forma segura
        $stmt = $pdo->prepare("UPDATE productos SET stock = stock - ? WHERE id = ?");
        $stmt->execute([$cantidad, $producto_id]);
        
        // Verificar que no quedó stock negativo
        $stmt = $pdo->prepare("SELECT stock FROM productos WHERE id = ?");
        $stmt->execute([$producto_id]);
        $stock_actual = $stmt->fetchColumn();
        
        if ($stock_actual < 0) {
            throw new Exception('Error: Stock negativo para producto ID ' . $producto_id);
        }
    }
    
    // Si es crédito, crear registro de crédito
    if ($metodo_pago === 'credito') {
        $config = getConfiguracion($pdo);
        $fecha_vencimiento = date('Y-m-d', strtotime('+' . $config['dias_vencimiento_credito'] . ' days'));
        
        $stmt = $pdo->prepare("
            INSERT INTO creditos (venta_id, cliente_id, monto_usd, fecha_vencimiento) 
            VALUES (?, ?, ?, ?)
        ");
        $stmt->execute([$venta_id, $cliente_id, $total_usd, $fecha_vencimiento]);
    }
    
    // Confirmar transacción
    $pdo->commit();
    
    json_response([
        'success' => true,
        'venta_id' => $venta_id,
        'message' => 'Venta registrada exitosamente'
    ]);
    
} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    
    error_log("Error en procesar_venta.php: " . $e->getMessage());
    
    json_response([
        'success' => false,
        'message' => 'Error al procesar la venta: ' . $e->getMessage()
    ]);
}
?>