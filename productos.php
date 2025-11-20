<?php
require_once 'config.php';
requireLogin();

$categorias = $pdo->query("SELECT * FROM categorias ORDER BY nombre")->fetchAll();
$config = getConfiguracion($pdo);

// Crear carpeta de imágenes si no existe
if (!file_exists('imagenes')) {
    mkdir('imagenes', 0777, true);
}

// Procesar acciones
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $imagen_nombre = null;
    
    // Procesar imagen si se subió
    if (isset($_FILES['imagen']) && $_FILES['imagen']['error'] === UPLOAD_ERR_OK) {
        $archivo = $_FILES['imagen'];
        $extension = strtolower(pathinfo($archivo['name'], PATHINFO_EXTENSION));
        $extensiones_permitidas = ['jpg', 'jpeg', 'png', 'webp', 'gif'];
        
        if (in_array($extension, $extensiones_permitidas)) {
            // Generar nombre único
            $imagen_nombre = uniqid() . '_' . time() . '.' . $extension;
            $ruta_destino = 'imagenes/' . $imagen_nombre;
            
            if (!move_uploaded_file($archivo['tmp_name'], $ruta_destino)) {
                $imagen_nombre = null;
                $_SESSION['error'] = 'Error al subir la imagen';
            }
        } else {
            $_SESSION['error'] = 'Formato de imagen no permitido';
        }
    }
    
    // Verificar código de barras duplicado
    if (!empty($_POST['codigo_barras'])) {
        $stmt = $pdo->prepare("SELECT id FROM productos WHERE codigo_barras = ? AND id != ?");
        $stmt->execute([$_POST['codigo_barras'], $_POST['id'] ?? 0]);
        if ($stmt->fetch()) {
            $_SESSION['error'] = 'El código de barras ya existe en otro producto';
            header('Location: productos.php');
            exit;
        }
    }
    
    if ($_POST['action'] === 'crear') {
        try {
            $stmt = $pdo->prepare("
                INSERT INTO productos (nombre, codigo_barras, categoria_id, costo_bs, precio_bs, precio_usd, stock, imagen, modo_calculo_precio, porcentaje_ganancia) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $_POST['nombre'],
                $_POST['codigo_barras'] ?: null,
                $_POST['categoria_id'],
                $_POST['costo_bs'],
                $_POST['precio_bs'],
                $_POST['precio_usd'],
                $_POST['stock'],
                $imagen_nombre,
                $_POST['modo_calculo'] ?? 'manual',
                $_POST['porcentaje_ganancia'] ?? 0
            ]);
            header('Location: productos.php?success=1');
            exit;
        } catch (PDOException $e) {
            $_SESSION['error'] = 'Error al crear el producto: ' . $e->getMessage();
            header('Location: productos.php');
            exit;
        }
    }
    
    if ($_POST['action'] === 'editar') {
        try {
            // Si hay nueva imagen, eliminar la anterior
            if ($imagen_nombre) {
                $stmt = $pdo->prepare("SELECT imagen FROM productos WHERE id = ?");
                $stmt->execute([$_POST['id']]);
                $producto_actual = $stmt->fetch();
                
                if ($producto_actual && $producto_actual['imagen'] && file_exists('imagenes/' . $producto_actual['imagen'])) {
                    unlink('imagenes/' . $producto_actual['imagen']);
                }
                
                $stmt = $pdo->prepare("
                    UPDATE productos 
                    SET nombre=?, codigo_barras=?, categoria_id=?, costo_bs=?, precio_bs=?, precio_usd=?, stock=?, imagen=?, modo_calculo_precio=?, porcentaje_ganancia=?
                    WHERE id=?
                ");
                $stmt->execute([
                    $_POST['nombre'],
                    $_POST['codigo_barras'] ?: null,
                    $_POST['categoria_id'],
                    $_POST['costo_bs'],
                    $_POST['precio_bs'],
                    $_POST['precio_usd'],
                    $_POST['stock'],
                    $imagen_nombre,
                    $_POST['modo_calculo'] ?? 'manual',
                    $_POST['porcentaje_ganancia'] ?? 0,
                    $_POST['id']
                ]);
            } else {
                $stmt = $pdo->prepare("
                    UPDATE productos 
                    SET nombre=?, codigo_barras=?, categoria_id=?, costo_bs=?, precio_bs=?, precio_usd=?, stock=?, modo_calculo_precio=?, porcentaje_ganancia=?
                    WHERE id=?
                ");
                $stmt->execute([
                    $_POST['nombre'],
                    $_POST['codigo_barras'] ?: null,
                    $_POST['categoria_id'],
                    $_POST['costo_bs'],
                    $_POST['precio_bs'],
                    $_POST['precio_usd'],
                    $_POST['stock'],
                    $_POST['modo_calculo'] ?? 'manual',
                    $_POST['porcentaje_ganancia'] ?? 0,
                    $_POST['id']
                ]);
            }
            
            header('Location: productos.php?success=2');
            exit;
        } catch (PDOException $e) {
            $_SESSION['error'] = 'Error al actualizar el producto: ' . $e->getMessage();
            header('Location: productos.php');
            exit;
        }
    }
}

// Procesar eliminación
if (isset($_GET['delete'])) {
    try {
        $producto_id = $_GET['delete'];
        
        // Verificar si el producto existe
        $stmt = $pdo->prepare("SELECT imagen FROM productos WHERE id = ?");
        $stmt->execute([$producto_id]);
        $producto = $stmt->fetch();
        
        if (!$producto) {
            $_SESSION['error'] = 'El producto no existe';
            header('Location: productos.php');
            exit;
        }
        
        // Verificar si el producto tiene ventas asociadas
        $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM detalle_ventas WHERE producto_id = ?");
        $stmt->execute([$producto_id]);
        $ventas_asociadas = $stmt->fetch();
        
        if ($ventas_asociadas['count'] > 0) {
            $_SESSION['error'] = 'No se puede eliminar el producto porque tiene ventas asociadas';
            header('Location: productos.php');
            exit;
        }
        
        // Eliminar imagen si existe
        if ($producto['imagen'] && file_exists('imagenes/' . $producto['imagen'])) {
            if (!unlink('imagenes/' . $producto['imagen'])) {
                $_SESSION['error'] = 'Error al eliminar la imagen del producto';
                header('Location: productos.php');
                exit;
            }
        }
        
        // Eliminar el producto
        $stmt = $pdo->prepare("DELETE FROM productos WHERE id = ?");
        $stmt->execute([$producto_id]);
        
        if ($stmt->rowCount() > 0) {
            header('Location: productos.php?success=3');
            exit;
        } else {
            $_SESSION['error'] = 'No se pudo eliminar el producto';
            header('Location: productos.php');
            exit;
        }
        
    } catch (PDOException $e) {
        $_SESSION['error'] = 'Error al eliminar el producto: ' . $e->getMessage();
        header('Location: productos.php');
        exit;
    }
}

$productos = $pdo->query("
    SELECT p.*, c.nombre as categoria 
    FROM productos p 
    JOIN categorias c ON p.categoria_id = c.id 
    ORDER BY p.nombre
")->fetchAll();
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Productos - Nine Market</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body { background-color: #f8f9fa; }
        .badge-stock-bajo { background-color: #dc3545; }
        .producto-imagen-small {
            width: 50px;
            height: 50px;
            object-fit: cover;
            border-radius: 8px;
            background: #f8f9fa;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .preview-imagen {
            max-width: 100%;
            max-height: 200px;
            border-radius: 8px;
            margin-top: 10px;
        }
        .imagen-upload-area {
            border: 2px dashed #198754;
            border-radius: 10px;
            padding: 20px;
            text-align: center;
            cursor: pointer;
            transition: all 0.3s;
        }
        .imagen-upload-area:hover {
            background: #f8f9fa;
            border-color: #146c43;
        }
        .imagen-preview-container {
            position: relative;
            display: inline-block;
        }
        .btn-remove-image {
            position: absolute;
            top: 5px;
            right: 5px;
            background: rgba(220, 53, 69, 0.9);
            color: white;
            border: none;
            border-radius: 50%;
            width: 30px;
            height: 30px;
            cursor: pointer;
            font-size: 14px;
        }
        .costo-section {
            background: #f8f9fa;
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 20px;
            border-left: 4px solid #198754;
        }
        .precio-section {
            background: #e8f5e8;
            border-radius: 10px;
            padding: 20px;
            border-left: 4px solid #28a745;
        }
        .calculation-card {
            background: white;
            border-radius: 10px;
            padding: 15px;
            margin-bottom: 15px;
            box-shadow: 0 2px 5px rgba(0,0,0,0.1);
        }
        .btn-switch-mode {
            border: 2px solid #198754;
            background: white;
            color: #198754;
            padding: 8px 15px;
            border-radius: 20px;
            font-weight: 500;
            transition: all 0.3s;
        }
        .btn-switch-mode.active {
            background: #198754;
            color: white;
        }
        .btn-switch-mode:hover:not(.active) {
            background: #e8f5e8;
        }
        .stock-info {
            background: #e7f3ff;
            border-radius: 8px;
            padding: 10px;
            margin-top: 5px;
            border-left: 3px solid #0d6efd;
        }
        .codigo-barras-section {
            background: #fff3cd;
            border-radius: 10px;
            padding: 15px;
            margin-bottom: 15px;
            border-left: 4px solid #ffc107;
        }
    </style>
</head>
<body>
    <?php include 'header.php'; ?>

    <div class="container-fluid" x-data="productosApp()">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h2>📦 Gestión de Productos</h2>
            <button class="btn btn-success" @click="abrirModalNuevo()">
                ➕ Nuevo Producto
            </button>
        </div>

        <?php if (isset($_GET['success'])): ?>
        <div class="alert alert-success alert-dismissible fade show">
            <?php
            $messages = [1 => 'Producto creado', 2 => 'Producto actualizado', 3 => 'Producto eliminado'];
            echo $messages[$_GET['success']];
            ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php endif; ?>

        <?php if (isset($_SESSION['error'])): ?>
        <div class="alert alert-danger alert-dismissible fade show">
            <?= htmlspecialchars($_SESSION['error']) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php unset($_SESSION['error']); ?>
        <?php endif; ?>

        <div class="card shadow-sm">
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-hover">
                        <thead>
                            <tr>
                                <th width="60">Imagen</th>
                                <th>Código Barras</th>
                                <th>Nombre</th>
                                <th>Categoría</th>
                                <th>Costo</th>
                                <th>Precio Bs</th>
                                <th>Precio USD</th>
                                <th>Stock</th>
                                <th>Margen</th>
                                <th>Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($productos as $p): 
                                $margen_bruto = $p['precio_bs'] - $p['costo_bs'];
                                $margen_pct = $p['precio_bs'] > 0 ? ($margen_bruto / $p['precio_bs']) * 100 : 0;
                            ?>
                            <tr>
                                <td>
                                    <?php if ($p['imagen']): ?>
                                        <img src="imagenes/<?= htmlspecialchars($p['imagen']) ?>" 
                                             class="producto-imagen-small"
                                             alt="<?= htmlspecialchars($p['nombre']) ?>">
                                    <?php else: ?>
                                        <div class="producto-imagen-small">📦</div>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($p['codigo_barras']): ?>
                                        <code class="bg-light p-1 rounded"><?= htmlspecialchars($p['codigo_barras']) ?></code>
                                    <?php else: ?>
                                        <span class="text-muted">-</span>
                                    <?php endif; ?>
                                </td>
                                <td><?= htmlspecialchars($p['nombre']) ?></td>
                                <td><?= htmlspecialchars($p['categoria']) ?></td>
                                <td><?= formatMoney($p['costo_bs']) ?></td>
                                <td><?= formatMoney($p['precio_bs']) ?></td>
                                <td>$<?= number_format($p['precio_usd'], 2) ?></td>
                                <td>
                                    <?= $p['stock'] ?>
                                    <?php if ($p['stock'] <= $p['stock_minimo']): ?>
                                        <span class="badge badge-stock-bajo">⚠️</span>
                                    <?php endif; ?>
                                </td>
                                <td><?= number_format($margen_pct, 1) ?>%</td>
                                <td>
                                    <button class="btn btn-sm btn-outline-primary" 
                                            @click='editarProducto(<?= json_encode($p) ?>)'>
                                        ✏️
                                    </button>
                                    <a href="?delete=<?= $p['id'] ?>" 
                                       class="btn btn-sm btn-outline-danger"
                                       onclick="return confirm('¿Estás seguro de eliminar el producto \"<?= addslashes($p['nombre']) ?>\"? Esta acción no se puede deshacer.')">
                                        🗑️
                                    </a>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- Modal Nuevo/Editar -->
        <div x-show="modalNuevo || modalEditar" 
             class="modal fade" 
             :class="{ 'show d-block': modalNuevo || modalEditar }"
             style="background: rgba(0,0,0,0.5)"
             @click.self="cerrarModal()">
            <div class="modal-dialog modal-lg">
                <div class="modal-content">
                    <form method="POST" enctype="multipart/form-data">
                        <input type="hidden" name="action" :value="modalEditar ? 'editar' : 'crear'">
                        <input type="hidden" name="id" x-model="producto.id">
                        <input type="hidden" name="modo_calculo" x-model="modoCalculo">
                        <input type="hidden" name="porcentaje_ganancia" x-model="porcentajeGanancia">
                        
                        <div class="modal-header">
                            <h5 class="modal-title" x-text="modalEditar ? 'Editar Producto' : 'Nuevo Producto'"></h5>
                            <button type="button" class="btn-close" @click="cerrarModal()"></button>
                        </div>
                        
                        <div class="modal-body">
                            <div class="row g-3">
                                <!-- Sección de imagen -->
                                <div class="col-12">
                                    <label class="form-label fw-bold">📸 Imagen del Producto</label>
                                    
                                    <div class="imagen-upload-area" @click="$refs.fileInput.click()">
                                        <div x-show="!imagenPreview && !producto.imagen">
                                            <div class="fs-1">📷</div>
                                            <p class="mb-0">Haz clic para seleccionar una imagen</p>
                                            <small class="text-muted">JPG, PNG, WEBP (máx. 5MB)</small>
                                        </div>
                                        
                                        <div x-show="imagenPreview || producto.imagen" class="imagen-preview-container">
                                            <img :src="imagenPreview || (producto.imagen ? 'imagenes/' + producto.imagen : '')" 
                                                 class="preview-imagen"
                                                 x-show="imagenPreview || producto.imagen">
                                            <button type="button" 
                                                    class="btn-remove-image"
                                                    @click.stop="limpiarImagen()"
                                                    x-show="imagenPreview">
                                                ✕
                                            </button>
                                        </div>
                                    </div>
                                    
                                    <input type="file" 
                                           name="imagen" 
                                           x-ref="fileInput"
                                           @change="previewImagen($event)"
                                           accept="image/jpeg,image/png,image/webp,image/gif"
                                           style="display: none;">
                                </div>

                                <div class="col-md-6">
                                    <label class="form-label">Nombre *</label>
                                    <input type="text" name="nombre" x-model="producto.nombre" class="form-control" required>
                                </div>

                                <!-- Sección mejorada de código de barras -->
                                <div class="col-md-6">
                                    <div class="codigo-barras-section">
                                        <label class="form-label fw-bold">
                                            📊 Código de Barras 
                                            <span class="text-muted fw-normal">(Opcional)</span>
                                        </label>
                                        <input type="text" 
                                               name="codigo_barras" 
                                               x-model="producto.codigo_barras" 
                                               class="form-control" 
                                               placeholder="Ej: 7501055300013"
                                               maxlength="20">
                                        <small class="text-muted">
                                            Único por producto. Se usa para búsquedas rápidas en el POS.
                                        </small>
                                        <div x-show="producto.codigo_barras" class="mt-2">
                                            <small class="text-success">
                                                ✅ Código configurado: 
                                                <code x-text="producto.codigo_barras"></code>
                                            </small>
                                        </div>
                                    </div>
                                </div>

                                <div class="col-md-4">
                                    <label class="form-label">Categoría *</label>
                                    <select name="categoria_id" x-model="producto.categoria_id" class="form-select" required>
                                        <?php foreach ($categorias as $cat): ?>
                                        <option value="<?= $cat['id'] ?>"><?= htmlspecialchars($cat['nombre']) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>

                                <!-- Sección de Costos -->
                                <div class="col-12 costo-section">
                                    <h6 class="fw-bold mb-3">💰 Cálculo de Costos</h6>
                                    
                                    <!-- Selector de modo -->
                                    <div class="d-flex gap-2 mb-3">
                                        <button type="button" 
                                                class="btn-switch-mode" 
                                                :class="{ 'active': modoCosto === 'unitario' }"
                                                @click="modoCosto = 'unitario'; resetearModoMayor()">
                                            💰 Costo Unitario
                                        </button>
                                        <button type="button" 
                                                class="btn-switch-mode" 
                                                :class="{ 'active': modoCosto === 'mayor' }"
                                                @click="modoCosto = 'mayor'">
                                            📦 Compra por Mayor
                                        </button>
                                    </div>

                                    <!-- Información de stock actual (solo en edición) -->
                                    <div x-show="modalEditar" class="stock-info mb-3">
                                        <small class="fw-bold">📊 Stock Actual:</small>
                                        <span x-text="stockActual" class="fw-bold text-primary"></span> unidades
                                    </div>

                                    <!-- Modo Costo Unitario -->
                                    <div x-show="modoCosto === 'unitario'" class="row g-3">
                                        <div class="col-md-6">
                                            <label class="form-label">Costo Unitario Bs *</label>
                                            <input type="number" 
                                                   name="costo_bs" 
                                                   x-model="producto.costo_bs" 
                                                   class="form-control" 
                                                   step="0.01" 
                                                   min="0" 
                                                   @input="calcularPreciosDesdeCosto()">
                                        </div>
                                        <div class="col-md-6">
                                            <label class="form-label">
                                                <span x-text="modalEditar ? 'Stock Total' : 'Stock Inicial'"></span>
                                            </label>
                                            <input type="number" 
                                                   name="stock" 
                                                   x-model="producto.stock" 
                                                   class="form-control" 
                                                   min="0" 
                                                   :placeholder="modalEditar ? 'Mantener o modificar stock' : '0'">
                                            <small class="text-muted" x-show="modalEditar">
                                                Stock actual: <span x-text="stockActual"></span> unidades
                                            </small>
                                        </div>
                                    </div>

                                    <!-- Modo Compra por Mayor -->
                                    <div x-show="modoCosto === 'mayor'" class="row g-3">
                                        <div class="col-md-4">
                                            <label class="form-label">Costo Total Compra Bs *</label>
                                            <input type="number" 
                                                   x-model="costoTotalCompra" 
                                                   class="form-control" 
                                                   step="0.01" 
                                                   min="0" 
                                                   @input="calcularCostoUnitario()"
                                                   placeholder="0.00">
                                        </div>
                                        <div class="col-md-4">
                                            <label class="form-label">
                                                <span x-text="modalEditar ? 'Cantidad a Agregar' : 'Cantidad Comprada'"></span> *
                                            </label>
                                            <input type="number" 
                                                   x-model="cantidadComprada" 
                                                   class="form-control" 
                                                   min="1" 
                                                   @input="calcularCostoUnitario()"
                                                   placeholder="0">
                                        </div>
                                        <div class="col-md-4">
                                            <label class="form-label">Costo Unitario Calculado</label>
                                            <input type="number" 
                                                   name="costo_bs" 
                                                   x-model="producto.costo_bs" 
                                                   class="form-control bg-light" 
                                                   readonly>
                                            <small class="text-muted">Calculado automáticamente</small>
                                        </div>
                                        <div class="col-12">
                                            <div class="calculation-card">
                                                <small class="text-muted d-block">Fórmula: </small>
                                                <code x-text="'Costo Unitario = ' + costoTotalCompra + ' Bs / ' + cantidadComprada + ' unidades = ' + (costoTotalCompra && cantidadComprada ? (costoTotalCompra / cantidadComprada).toFixed(2) : '0.00') + ' Bs'"></code>
                                                <div class="mt-2" x-show="modalEditar && cantidadComprada > 0">
                                                    <small class="text-muted">Stock resultante: </small>
                                                    <strong x-text="stockActual + parseInt(cantidadComprada || 0) + ' unidades'"></strong>
                                                    <small class="text-muted d-block">
                                                        (Actual: <span x-text="stockActual"></span> + Nuevo: <span x-text="cantidadComprada"></span>)
                                                    </small>
                                                </div>
                                                <div class="mt-2" x-show="!modalEditar && cantidadComprada > 0">
                                                    <small class="text-muted">Stock inicial: </small>
                                                    <strong x-text="cantidadComprada + ' unidades'"></strong>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <!-- Sección de Precios de Venta -->
                                <div class="col-12 precio-section">
                                    <h6 class="fw-bold mb-3">🏷️ Precios de Venta</h6>
                                    
                                    <!-- Selector de modo de cálculo -->
                                    <div class="mb-3">
                                        <label class="form-label fw-bold">Modo de Cálculo</label>
                                        <div class="btn-group w-100" role="group">
                                            <input type="radio" class="btn-check" name="modo_calculo_radio" id="modo_manual" 
                                                   x-model="modoCalculo" value="manual" autocomplete="off">
                                            <label class="btn btn-outline-primary" for="modo_manual">💰 Precio Manual</label>
                                            
                                            <input type="radio" class="btn-check" name="modo_calculo_radio" id="modo_porcentaje" 
                                                   x-model="modoCalculo" value="porcentaje" autocomplete="off">
                                            <label class="btn btn-outline-success" for="modo_porcentaje">📊 Por Porcentaje</label>
                                        </div>
                                    </div>

                                    <!-- Modo Manual -->
                                    <div x-show="modoCalculo === 'manual'" class="row g-3">
                                        <div class="col-md-6">
                                            <label class="form-label">Precio de Venta Bs *</label>
                                            <input type="number" 
                                                   name="precio_bs" 
                                                   x-model="producto.precio_bs" 
                                                   class="form-control" 
                                                   step="0.01" 
                                                   min="0" 
                                                   @input="calcularUsd()" 
                                                   required>
                                            <small class="text-muted">
                                                Precio final al cliente
                                            </small>
                                        </div>
                                        <div class="col-md-6">
                                            <label class="form-label">Precio de Venta USD *</label>
                                            <input type="number" 
                                                   name="precio_usd" 
                                                   x-model="producto.precio_usd" 
                                                   class="form-control bg-light" 
                                                   step="0.01" 
                                                   min="0" 
                                                   readonly 
                                                   required>
                                            <small class="text-muted">
                                                Calculado automáticamente
                                            </small>
                                        </div>
                                    </div>

                                    <!-- Modo Porcentaje -->
                                    <div x-show="modoCalculo === 'porcentaje'" class="row g-3">
                                        <div class="col-md-4">
                                            <label class="form-label">Porcentaje de Ganancia (%) *</label>
                                            <input type="number" 
                                                   x-model="porcentajeGanancia" 
                                                   class="form-control" 
                                                   step="0.01" 
                                                   min="0" 
                                                   max="1000"
                                                   @input="calcularPrecioPorPorcentaje()"
                                                   placeholder="30">
                                            <small class="text-muted">
                                                Ej: 30% de ganancia sobre el costo
                                            </small>
                                        </div>
                                        <div class="col-md-4">
                                            <label class="form-label">Precio Calculado Bs</label>
                                            <input type="number" 
                                                   x-model="precioCalculadoSinIva" 
                                                   class="form-control bg-light" 
                                                   readonly>
                                            <small class="text-muted">
                                                Costo + Ganancia
                                            </small>
                                        </div>
                                        <div class="col-md-4">
                                            <label class="form-label">Precio Final Bs</label>
                                            <input type="number" 
                                                   name="precio_bs" 
                                                   x-model="producto.precio_bs" 
                                                   class="form-control bg-success text-white fw-bold" 
                                                   readonly 
                                                   required>
                                            <small class="text-muted">
                                                Precio de venta final
                                            </small>
                                        </div>
                                        
                                        <!-- Fórmula de cálculo -->
                                        <div class="col-12">
                                            <div class="calculation-card">
                                                <h6 class="mb-2">📝 Fórmula de Cálculo:</h6>
                                                <code>
                                                    <span x-text="'Precio = ' + producto.costo_bs + ' / (1 - ' + (porcentajeGanancia/100) + ') = ' + producto.precio_bs.toFixed(2)"></span>
                                                </code>
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <!-- Información de Margen -->
                                    <div class="col-12 mt-3" x-show="producto.costo_bs > 0 && producto.precio_bs > 0">
                                        <div class="calculation-card">
                                            <div class="row text-center">
                                                <div class="col-3">
                                                    <small class="text-muted d-block">Margen Bruto</small>
                                                    <strong x-text="'Bs ' + (producto.precio_bs - producto.costo_bs).toFixed(2)"></strong>
                                                </div>
                                                <div class="col-3">
                                                    <small class="text-muted d-block">Margen %</small>
                                                    <strong x-text="((producto.precio_bs - producto.costo_bs) / producto.precio_bs * 100).toFixed(1) + '%'"></strong>
                                                </div>
                                                <div class="col-3">
                                                    <small class="text-muted d-block">ROI</small>
                                                    <strong x-text="((producto.precio_bs - producto.costo_bs) / producto.costo_bs * 100).toFixed(1) + '%'"></strong>
                                                </div>
                                                <div class="col-3">
                                                    <small class="text-muted d-block">Precio USD</small>
                                                    <strong x-text="'$' + producto.precio_usd.toFixed(2)"></strong>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" @click="cerrarModal()">Cancelar</button>
                            <button type="submit" class="btn btn-success">
                                <span x-show="!modalEditar">➕ Crear Producto</span>
                                <span x-show="modalEditar">💾 Guardar Cambios</span>
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/alpinejs@3.13.10/dist/cdn.min.js" defer></script>
    <script>
        function productosApp() {
            return {
                modalNuevo: false,
                modalEditar: false,
                imagenPreview: null,
                modoCosto: 'unitario', // 'unitario' o 'mayor'
                costoTotalCompra: 0,
                cantidadComprada: 0,
                stockActual: 0, // Stock actual del producto (solo en edición)
                producto: {
                    id: '',
                    nombre: '',
                    codigo_barras: '',
                    categoria_id: 1,
                    costo_bs: 0,
                    precio_bs: 0,
                    precio_usd: 0,
                    stock: 0,
                    imagen: '',
                    modo_calculo_precio: 'manual',
                    porcentaje_ganancia: 0
                },
                tasaCambio: <?= $config['tasa_cambio'] ?>,
                modoCalculo: 'manual',
                porcentajeGanancia: 30,
                precioCalculadoSinIva: 0,

                abrirModalNuevo() {
                    this.modalNuevo = true;
                    this.modalEditar = false;
                    this.modoCosto = 'unitario';
                    this.costoTotalCompra = 0;
                    this.cantidadComprada = 0;
                    this.stockActual = 0;
                    this.modoCalculo = 'manual';
                    this.porcentajeGanancia = 30;
                    this.precioCalculadoSinIva = 0;
                },

                editarProducto(prod) {
                    this.producto = {...prod};
                    this.stockActual = prod.stock;
                    this.imagenPreview = null;
                    this.modalEditar = true;
                    this.modalNuevo = false;
                    this.modoCosto = 'unitario';
                    this.costoTotalCompra = 0;
                    this.cantidadComprada = 0;
                    this.modoCalculo = prod.modo_calculo_precio || 'manual';
                    this.porcentajeGanancia = prod.porcentaje_ganancia || 30;
                    
                    // Si el modo es porcentaje, calcular el precio
                    if (this.modoCalculo === 'porcentaje') {
                        this.calcularPrecioPorPorcentaje();
                    }
                },

                calcularCostoUnitario() {
                    if (this.costoTotalCompra > 0 && this.cantidadComprada > 0) {
                        this.producto.costo_bs = (this.costoTotalCompra / this.cantidadComprada).toFixed(2);
                        
                        if (this.modalEditar) {
                            this.producto.stock = this.stockActual + parseInt(this.cantidadComprada);
                        } else {
                            this.producto.stock = parseInt(this.cantidadComprada);
                        }
                        
                        // Recalcular precio si está en modo porcentaje
                        if (this.modoCalculo === 'porcentaje') {
                            this.calcularPrecioPorPorcentaje();
                        }
                    }
                },

                resetearModoMayor() {
                    this.costoTotalCompra = 0;
                    this.cantidadComprada = 0;
                    
                    if (this.modalEditar) {
                        this.producto.stock = this.stockActual;
                    } else {
                        this.producto.stock = 0;
                    }
                },

                calcularPrecioPorPorcentaje() {
                    if (this.producto.costo_bs > 0 && this.porcentajeGanancia > 0) {
                        // Calcular precio: Costo / (1 - porcentaje_ganancia/100)
                        this.precioCalculadoSinIva = this.producto.costo_bs / (1 - (this.porcentajeGanancia / 100));
                        
                        // El precio final es el precio calculado (sin IVA)
                        this.producto.precio_bs = this.precioCalculadoSinIva;
                        
                        // Calcular USD
                        this.calcularUsd();
                    }
                },

                calcularUsd() {
                    this.producto.precio_usd = (this.producto.precio_bs / this.tasaCambio).toFixed(2);
                },

                previewImagen(event) {
                    const file = event.target.files[0];
                    if (file) {
                        if (file.size > 5 * 1024 * 1024) {
                            alert('❌ La imagen es muy grande. Máximo 5MB');
                            event.target.value = '';
                            return;
                        }

                        const tiposPermitidos = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];
                        if (!tiposPermitidos.includes(file.type)) {
                            alert('❌ Formato no permitido. Usa JPG, PNG, WEBP o GIF');
                            event.target.value = '';
                            return;
                        }

                        const reader = new FileReader();
                        reader.onload = (e) => {
                            this.imagenPreview = e.target.result;
                        };
                        reader.readAsDataURL(file);
                    }
                },

                limpiarImagen() {
                    this.imagenPreview = null;
                    this.$refs.fileInput.value = '';
                },

                cerrarModal() {
                    this.modalNuevo = false;
                    this.modalEditar = false;
                    this.imagenPreview = null;
                    this.modoCosto = 'unitario';
                    this.costoTotalCompra = 0;
                    this.cantidadComprada = 0;
                    this.stockActual = 0;
                    this.modoCalculo = 'manual';
                    this.porcentajeGanancia = 30;
                    this.precioCalculadoSinIva = 0;
                    this.producto = {
                        id: '', nombre: '', codigo_barras: '', categoria_id: 1,
                        costo_bs: 0, precio_bs: 0, precio_usd: 0, stock: 0, imagen: '',
                        modo_calculo_precio: 'manual', porcentaje_ganancia: 0
                    };
                }
            }
        }
    </script>
</body>
</html>