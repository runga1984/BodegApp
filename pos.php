
<?php
require_once 'config.php';
requireLogin();

$config = getConfiguracion($pdo);
$productos = $pdo->query("SELECT p.*, c.nombre as categoria FROM productos p JOIN categorias c ON p.categoria_id = c.id ORDER BY p.nombre")->fetchAll();
$clientes = $pdo->query("SELECT * FROM clientes ORDER BY nombre")->fetchAll();
$categorias = $pdo->query("SELECT * FROM categorias ORDER BY nombre")->fetchAll();
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>POS - Nine Market</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body { background-color: #f8f9fa; }
        
        /* Estilos para categorías */
        .categorias-bar {
            background: white;
            border-radius: 10px;
            padding: 12px;
            margin-bottom: 15px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
        }
        .btn-categoria {
            margin: 3px;
            border-radius: 15px;
            padding: 6px 15px;
            font-weight: 500;
            font-size: 0.85rem;
            transition: all 0.3s;
        }
        .btn-categoria:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(0,0,0,0.2);
        }
        .btn-categoria.active {
            background: linear-gradient(135deg, #198754 0%, #146c43 100%);
            color: white;
            border: none;
        }
        
        /* Estilos para productos en grid - MÁS COMPACTOS */
        .productos-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(140px, 1fr));
            gap: 12px;
            max-height: 600px;
            overflow-y: auto;
            padding: 8px;
        }
        .producto-card {
            background: white;
            border-radius: 10px;
            padding: 10px;
            cursor: pointer;
            transition: all 0.3s;
            box-shadow: 0 2px 6px rgba(0,0,0,0.1);
            position: relative;
            overflow: hidden;
            height: 160px; /* Altura fija más compacta */
            display: flex;
            flex-direction: column;
        }
        .producto-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
        }
        .producto-card:active {
            transform: scale(0.98);
        }
        .producto-imagen {
            width: 100%;
            height: 70px; /* Reducida de 120px a 70px */
            object-fit: cover;
            border-radius: 6px;
            background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 2rem; /* Reducido de 3rem */
            margin-bottom: 8px;
            flex-shrink: 0;
        }
        .producto-imagen img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            border-radius: 6px;
        }
        .producto-nombre {
            font-size: 0.8rem; /* Reducido de 0.9rem */
            font-weight: 600;
            color: #212529;
            margin-bottom: 6px;
            height: 32px; /* Reducido de 40px */
            overflow: hidden;
            display: -webkit-box;
            -webkit-line-clamp: 2;
            -webkit-box-orient: vertical;
            line-height: 1.2;
            flex-grow: 1;
        }
        .producto-precio {
            font-size: 0.95rem; /* Reducido de 1.1rem */
            font-weight: bold;
            color: #198754;
            margin-bottom: 3px;
        }
        .producto-precio-usd {
            font-size: 0.75rem; /* Reducido de 0.85rem */
            color: #6c757d;
        }
        .producto-stock {
            position: absolute;
            top: 6px;
            right: 6px;
            background: rgba(0,0,0,0.7);
            color: white;
            padding: 2px 6px;
            border-radius: 10px;
            font-size: 0.7rem; /* Reducido de 0.75rem */
            font-weight: 600;
        }
        .producto-stock.bajo {
            background: #dc3545;
            animation: pulse 2s infinite;
        }
        .producto-codigo {
            position: absolute;
            top: 6px;
            left: 6px;
            background: rgba(255, 193, 7, 0.9);
            color: #000;
            padding: 2px 6px;
            border-radius: 10px;
            font-size: 0.6rem;
            font-weight: 600;
            max-width: 60px;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }
        @keyframes pulse {
            0%, 100% { opacity: 1; }
            50% { opacity: 0.7; }
        }
        
        /* Scrollbar personalizado */
        .productos-grid::-webkit-scrollbar {
            width: 6px;
        }
        .productos-grid::-webkit-scrollbar-track {
            background: #f1f1f1;
            border-radius: 8px;
        }
        .productos-grid::-webkit-scrollbar-thumb {
            background: #198754;
            border-radius: 8px;
        }
        .productos-grid::-webkit-scrollbar-thumb:hover {
            background: #146c43;
        }

        /* ===== MEJORAS PARA MÓVILES ===== */
        
        /* Carrito móvil - Bottom Sheet */
        .cart-mobile-container {
            position: fixed;
            bottom: 0;
            left: 0;
            right: 0;
            background: white;
            border-radius: 20px 20px 0 0;
            box-shadow: 0 -5px 25px rgba(0,0,0,0.2);
            z-index: 1050;
            transform: translateY(100%);
            transition: transform 0.3s ease;
            max-height: 80vh;
            overflow: hidden;
        }
        .cart-mobile-container.show {
            transform: translateY(0);
        }
        .cart-mobile-header {
            padding: 15px;
            border-bottom: 1px solid #dee2e6;
            display: flex;
            justify-content: space-between;
            align-items: center;
            background: linear-gradient(135deg, #198754 0%, #146c43 100%);
            color: white;
            border-radius: 20px 20px 0 0;
        }
        .cart-mobile-body {
            padding: 15px;
            max-height: 50vh;
            overflow-y: auto;
        }
        .cart-mobile-footer {
            padding: 15px;
            border-top: 1px solid #dee2e6;
            background: #f8f9fa;
        }
        
        /* Botón flotante del carrito móvil */
        .cart-fab {
            position: fixed;
            bottom: 20px;
            right: 20px;
            width: 60px;
            height: 60px;
            border-radius: 50%;
            background: linear-gradient(135deg, #198754 0%, #146c43 100%);
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
            box-shadow: 0 4px 15px rgba(25, 135, 84, 0.4);
            z-index: 1040;
            border: none;
        }
        .cart-badge {
            position: absolute;
            top: -5px;
            right: -5px;
            background: #dc3545;
            color: white;
            border-radius: 50%;
            width: 24px;
            height: 24px;
            font-size: 0.75rem;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
        }
        
        /* Items del carrito en móvil */
        .cart-item-mobile {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 10px 0;
            border-bottom: 1px solid #eee;
        }
        .cart-item-mobile:last-child {
            border-bottom: none;
        }
        .cart-item-info {
            flex: 1;
        }
        .cart-item-name {
            font-weight: 600;
            margin-bottom: 3px;
            font-size: 0.85rem;
        }
        .cart-item-price {
            color: #198754;
            font-weight: bold;
            font-size: 0.85rem;
        }
        .cart-item-controls {
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .quantity-input-mobile {
            width: 55px;
            text-align: center;
            border: 1px solid #ddd;
            border-radius: 6px;
            padding: 4px;
            font-size: 0.85rem;
        }
        .btn-remove-mobile {
            background: #dc3545;
            color: white;
            border: none;
            border-radius: 6px;
            width: 32px;
            height: 32px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 0.8rem;
        }
        
        /* Totales móvil */
        .totales-mobile {
            background: linear-gradient(135deg, #198754 0%, #146c43 100%);
            color: white;
            border-radius: 12px;
            padding: 15px;
            margin-bottom: 12px;
        }
        
        /* Overlay para el carrito móvil */
        .cart-overlay {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0,0,0,0.5);
            z-index: 1045;
            opacity: 0;
            visibility: hidden;
            transition: all 0.3s ease;
        }
        .cart-overlay.show {
            opacity: 1;
            visibility: visible;
        }
        
        /* Ajustes responsive para productos */
        @media (max-width: 768px) {
            .productos-grid {
                grid-template-columns: repeat(auto-fill, minmax(110px, 1fr));
                gap: 8px;
                max-height: none;
                padding: 5px;
            }
            .producto-card {
                padding: 6px;
                height: 140px; /* Aún más compacto en móviles */
            }
            .producto-imagen {
                height: 60px;
                font-size: 1.5rem;
                margin-bottom: 5px;
            }
            .producto-nombre {
                font-size: 0.75rem;
                height: 28px;
                line-height: 1.1;
            }
            .producto-precio {
                font-size: 0.85rem;
            }
            .producto-precio-usd {
                font-size: 0.7rem;
            }
            .producto-stock {
                top: 4px;
                right: 4px;
                padding: 1px 4px;
                font-size: 0.65rem;
            }
            .producto-codigo {
                top: 4px;
                left: 4px;
                padding: 1px 4px;
                font-size: 0.55rem;
                max-width: 50px;
            }
            
            /* Ocultar carrito de escritorio en móviles */
            .cart-desktop {
                display: none !important;
            }

            /* Categorías más compactas en móviles */
            .categorias-bar {
                padding: 8px;
            }
            .btn-categoria {
                padding: 4px 10px;
                font-size: 0.75rem;
                margin: 2px;
            }
        }
        
        @media (min-width: 769px) {
            .cart-mobile-container,
            .cart-fab,
            .cart-overlay {
                display: none !important;
            }
            .cart-desktop {
                display: block !important;
            }
        }
        
        /* Mejoras para inputs en móvil */
        @media (max-width: 768px) {
            .form-control, .form-select {
                font-size: 16px; /* Previene zoom en iOS */
            }
            
            .btn-lg-mobile {
                padding: 10px 16px;
                font-size: 1rem;
            }
        }

        /* Estilos para el carrito de escritorio */
        .cart-table {
            max-height: 400px;
            overflow-y: auto;
        }
        .total-box {
            background: linear-gradient(135deg, #198754 0%, #146c43 100%);
            color: white;
            border-radius: 12px;
            padding: 20px;
        }
        .btn-remove {
            cursor: pointer;
            padding: 5px;
            border-radius: 4px;
            transition: background-color 0.2s;
        }
        .btn-remove:hover {
            background-color: #f8f9fa;
        }
    </style>
</head>
<body>
    <?php include 'header.php'; ?>

    <div class="container-fluid" x-data="posApp()" x-init="init()">
        <div class="row">
            <!-- Columna izquierda: Búsqueda y productos -->
            <div class="col-lg-7">
                <div class="card shadow-sm mb-3">
                    <div class="card-body">
                        <div class="mb-3">
                            <label class="form-label fw-bold">🔍 Buscar Producto</label>
                            <input type="text" 
                                   x-model="busqueda" 
                                   @input="buscar()"
                                   @keydown.enter.prevent="agregarPrimero()"
                                   class="form-control form-control-lg" 
                                   placeholder="Nombre, código de barras..."
                                   autofocus>
                            <small class="text-muted">
                                Puedes buscar por nombre o código de barras
                            </small>
                        </div>
                    </div>
                </div>

                <!-- Filtro por categorías -->
                <div class="categorias-bar">
                    <div class="d-flex flex-wrap align-items-center">
                        <strong class="me-3" style="font-size: 0.9rem;">Categorías:</strong>
                        <button @click="categoriaSeleccionada = null; filtrarPorCategoria()" 
                                class="btn btn-sm btn-outline-success btn-categoria"
                                :class="{ 'active': categoriaSeleccionada === null }">
                            🏷️ Todas
                        </button>
                        <?php foreach ($categorias as $cat): ?>
                        <button @click="categoriaSeleccionada = <?= $cat['id'] ?>; filtrarPorCategoria()" 
                                class="btn btn-sm btn-outline-success btn-categoria"
                                :class="{ 'active': categoriaSeleccionada === <?= $cat['id'] ?> }">
                            <?= htmlspecialchars($cat['nombre']) ?>
                        </button>
                        <?php endforeach; ?>
                    </div>
                </div>

                <!-- Grid de productos MÁS COMPACTO -->
                <div class="card shadow-sm">
                    <div class="card-body" style="padding: 12px;">
                        <div x-show="busqueda.length > 0 && resultadosBusqueda.length === 0" 
                             class="text-center text-muted py-4">
                            <p>❌ No se encontraron productos</p>
                        </div>

                        <div class="productos-grid">
                            <template x-for="producto in productosFiltrados" :key="producto.id">
                                <div @click="agregarProducto(producto)" class="producto-card">
                                    <div class="producto-stock" 
                                         :class="{ 'bajo': producto.stock <= producto.stock_minimo }">
                                        📦 <span x-text="producto.stock"></span>
                                    </div>
                                    
                                    <!-- Mostrar código de barras si existe -->
                                    <div x-show="producto.codigo_barras" class="producto-codigo" :title="producto.codigo_barras">
                                        <span x-text="producto.codigo_barras"></span>
                                    </div>
                                    
                                    <div class="producto-imagen">
                                        <template x-if="producto.imagen">
                                            <img :src="'imagenes/' + producto.imagen" 
                                                 :alt="producto.nombre"
                                                 @error="$el.parentElement.innerHTML = '📦'">
                                        </template>
                                        <template x-if="!producto.imagen">
                                            <span>📦</span>
                                        </template>
                                    </div>
                                    
                                    <div class="producto-nombre" x-text="producto.nombre"></div>
                                    <div class="producto-precio" x-text="'Bs ' + parseFloat(producto.precio_bs).toFixed(2)"></div>
                                    <div class="producto-precio-usd" x-text="'$' + parseFloat(producto.precio_usd).toFixed(2)"></div>
                                </div>
                            </template>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Columna derecha: Carrito y totales (DESKTOP) -->
            <div class="col-lg-5 cart-desktop">
                <div class="card shadow-sm cart-table mb-3">
                    <div class="card-header bg-success text-white">
                        <h5 class="mb-0">🛒 Carrito de Venta</h5>
                    </div>
                    <div class="card-body">
                        <div x-show="items.length === 0" class="text-center text-muted py-5">
                            <p class="fs-1">🛒</p>
                            <p>El carrito está vacío</p>
                            <small>Haz clic en un producto para agregarlo</small>
                        </div>

                        <div x-show="items.length > 0">
                            <table class="table table-sm">
                                <thead>
                                    <tr>
                                        <th>Producto</th>
                                        <th width="80">Cant.</th>
                                        <th width="100" class="text-end">Total</th>
                                        <th width="40"></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <template x-for="(item, index) in items" :key="item.id">
                                        <tr>
                                            <td>
                                                <small x-text="item.nombre"></small>
                                                <br>
                                                <small class="text-muted" x-text="'Bs ' + parseFloat(item.precio_bs).toFixed(2)"></small>
                                            </td>
                                            <td>
                                                <input type="number" 
                                                       x-model.number="item.cantidad" 
                                                       @input="calcularTotales()"
                                                       min="1" 
                                                       :max="item.stock"
                                                       class="form-control form-control-sm">
                                            </td>
                                            <td class="text-end fw-bold" x-text="'Bs ' + (item.cantidad * item.precio_bs).toFixed(2)"></td>
                                            <td>
                                                <span @click="eliminarItem(index)" class="btn-remove">❌</span>
                                            </td>
                                        </tr>
                                    </template>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <!-- Totales -->
                <div class="total-box shadow mb-3">
                    <div class="row g-2">
                        <div class="col-6">
                            <small>Subtotal:</small>
                            <div class="fs-6" x-text="'Bs ' + subtotal.toFixed(2)"></div>
                        </div>
                        <div class="col-6 text-end">
                            <small>IVA (16%):</small>
                            <div class="fs-6" x-text="'Bs ' + ivaTotal.toFixed(2)"></div>
                        </div>
                        <div class="col-12"><hr class="my-2 border-white opacity-25"></div>
                        <div class="col-6">
                            <small>TOTAL Bs:</small>
                            <div class="fs-4 fw-bold" x-text="'Bs ' + totalBs.toFixed(2)"></div>
                        </div>
                        <div class="col-6 text-end">
                            <small>TOTAL USD:</small>
                            <div class="fs-4 fw-bold" x-text="'$' + totalUsd.toFixed(2)"></div>
                        </div>
                    </div>
                </div>

                <!-- Opciones de venta -->
                <div class="card shadow-sm" x-show="items.length > 0">
                    <div class="card-body">
                        <div class="mb-3">
                            <label class="form-label">Cliente</label>
                            <select x-model="clienteId" class="form-select">
                                <?php foreach ($clientes as $cliente): ?>
                                <option value="<?= $cliente['id'] ?>"><?= htmlspecialchars($cliente['nombre']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Método de Pago</label>
                            <select x-model="metodoPago" class="form-select">
                                <option value="efectivo">💵 Efectivo</option>
                                <option value="credito">💳 Crédito (7 días)</option>
                            </select>
                        </div>

                        <button @click="finalizarVenta()" 
                                class="btn btn-success btn-lg w-100"
                                :disabled="procesando">
                            <span x-show="!procesando">✅ Finalizar Venta</span>
                            <span x-show="procesando">⏳ Procesando...</span>
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <!-- ===== CARRITO MÓVIL ===== -->
        
        <!-- Overlay -->
        <div class="cart-overlay" :class="{ 'show': carritoMovilAbierto }" 
             @click="carritoMovilAbierto = false"></div>
        
        <!-- Botón flotante del carrito -->
        <button class="cart-fab" @click="carritoMovilAbierto = true">
            🛒
            <div class="cart-badge" x-show="items.length > 0" x-text="items.length"></div>
        </button>
        
        <!-- Panel del carrito móvil -->
        <div class="cart-mobile-container" :class="{ 'show': carritoMovilAbierto }">
            <div class="cart-mobile-header">
                <h5 class="mb-0">🛒 Carrito (<span x-text="items.length"></span>)</h5>
                <button class="btn btn-sm btn-light" @click="carritoMovilAbierto = false">
                    ✕
                </button>
            </div>
            
            <div class="cart-mobile-body">
                <div x-show="items.length === 0" class="text-center text-muted py-4">
                    <p class="fs-1">🛒</p>
                    <p>El carrito está vacío</p>
                    <small>Haz clic en un producto para agregarlo</small>
                </div>
                
                <template x-for="(item, index) in items" :key="item.id">
                    <div class="cart-item-mobile">
                        <div class="cart-item-info">
                            <div class="cart-item-name" x-text="item.nombre"></div>
                            <div class="cart-item-price" x-text="'Bs ' + parseFloat(item.precio_bs).toFixed(2)"></div>
                        </div>
                        <div class="cart-item-controls">
                            <input type="number" 
                                   x-model.number="item.cantidad" 
                                   @input="calcularTotales()"
                                   min="1" 
                                   :max="item.stock"
                                   class="form-control quantity-input-mobile">
                            <button @click="eliminarItem(index)" class="btn-remove-mobile">
                                ✕
                            </button>
                        </div>
                    </div>
                </template>
            </div>
            
            <div class="cart-mobile-footer">
                <!-- Totales móvil -->
                <div class="totales-mobile mb-3">
                    <div class="row g-2 text-center">
                        <div class="col-6">
                            <small>SUBTOTAL:</small>
                            <div class="fs-6" x-text="'Bs ' + subtotal.toFixed(2)"></div>
                        </div>
                        <div class="col-6">
                            <small>IVA (16%):</small>
                            <div class="fs-6" x-text="'Bs ' + ivaTotal.toFixed(2)"></div>
                        </div>
                        <div class="col-12"><hr class="my-2 border-white opacity-50"></div>
                        <div class="col-6">
                            <small>TOTAL Bs:</small>
                            <div class="fs-5 fw-bold" x-text="'Bs ' + totalBs.toFixed(2)"></div>
                        </div>
                        <div class="col-6">
                            <small>TOTAL USD:</small>
                            <div class="fs-5 fw-bold" x-text="'$' + totalUsd.toFixed(2)"></div>
                        </div>
                    </div>
                </div>
                
                <!-- Opciones de venta móvil -->
                <div x-show="items.length > 0">
                    <div class="mb-3">
                        <label class="form-label">Cliente</label>
                        <select x-model="clienteId" class="form-select">
                            <?php foreach ($clientes as $cliente): ?>
                            <option value="<?= $cliente['id'] ?>"><?= htmlspecialchars($cliente['nombre']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Método de Pago</label>
                        <select x-model="metodoPago" class="form-select">
                            <option value="efectivo">💵 Efectivo</option>
                            <option value="credito">💳 Crédito (7 días)</option>
                        </select>
                    </div>

                    <button @click="finalizarVenta()" 
                            class="btn btn-success btn-lg w-100 btn-lg-mobile"
                            :disabled="procesando">
                        <span x-show="!procesando">✅ Finalizar Venta</span>
                        <span x-show="procesando">⏳ Procesando...</span>
                    </button>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/alpinejs@3.13.10/dist/cdn.min.js" defer></script>
    <script>
        function posApp() {
            return {
                productos: <?= json_encode($productos) ?>,
                clientes: <?= json_encode($clientes) ?>,
                items: [],
                busqueda: '',
                categoriaSeleccionada: null,
                resultadosBusqueda: [],
                productosFiltrados: [],
                tasaCambio: <?= $config['tasa_cambio'] ?>,
                clienteId: 1,
                metodoPago: 'efectivo',
                subtotal: 0,
                ivaTotal: 0,
                totalBs: 0,
                totalUsd: 0,
                procesando: false,
                carritoMovilAbierto: false,

                init() {
                    console.log('POS iniciado con ' + this.productos.length + ' productos');
                    this.filtrarPorCategoria();
                },

                filtrarPorCategoria() {
                    if (this.categoriaSeleccionada === null) {
                        this.productosFiltrados = [...this.productos];
                    } else {
                        this.productosFiltrados = this.productos.filter(p => 
                            p.categoria_id == this.categoriaSeleccionada
                        );
                    }
                    
                    // Si hay búsqueda activa, aplicar filtro adicional
                    if (this.busqueda.length >= 2) {
                        this.buscar();
                    }
                },

                buscar() {
                    if (this.busqueda.length < 2) {
                        this.filtrarPorCategoria();
                        return;
                    }

                    const term = this.busqueda.toLowerCase();
                    
                    // Buscar en productos filtrados por categoría
                    let base = this.categoriaSeleccionada === null ? this.productos : 
                               this.productos.filter(p => p.categoria_id == this.categoriaSeleccionada);
                    
                    this.productosFiltrados = base.filter(p => {
                        return p.nombre.toLowerCase().includes(term) || 
                               (p.codigo_barras && p.codigo_barras.includes(term));
                    });
                },

                agregarPrimero() {
                    if (this.productosFiltrados.length > 0) {
                        this.agregarProducto(this.productosFiltrados[0]);
                    }
                },

                agregarProducto(producto) {
                    if (producto.stock <= 0) {
                        alert('❌ Producto sin stock disponible');
                        return;
                    }

                    const existe = this.items.find(item => item.id === producto.id);
                    
                    if (existe) {
                        if (existe.cantidad < producto.stock) {
                            existe.cantidad++;
                        } else {
                            alert('❌ No hay más stock disponible');
                            return;
                        }
                    } else {
                        this.items.push({
                            id: producto.id,
                            nombre: producto.nombre,
                            precio_bs: parseFloat(producto.precio_bs),
                            precio_usd: parseFloat(producto.precio_usd),
                            stock: producto.stock,
                            cantidad: 1
                        });
                    }

                    this.busqueda = '';
                    this.calcularTotales();
                    
                    // En móviles, abrir el carrito automáticamente
                    if (window.innerWidth < 769) {
                        this.carritoMovilAbierto = true;
                    }
                    
                    // Feedback visual
                    console.log('✅ Producto agregado:', producto.nombre);
                },

                eliminarItem(index) {
                    this.items.splice(index, 1);
                    this.calcularTotales();
                },

                calcularTotales() {
                    let subtotal = 0;

                    this.items.forEach(item => {
                        subtotal += item.cantidad * item.precio_bs;
                    });

                    this.subtotal = subtotal;
                    this.ivaTotal = subtotal * 0.16;
                    this.totalBs = this.subtotal + this.ivaTotal;
                    this.totalUsd = this.totalBs / this.tasaCambio;
                },

                async finalizarVenta() {
                    if (this.items.length === 0) {
                        alert('❌ El carrito está vacío');
                        return;
                    }

                    if (!confirm('¿Confirmar venta por ' + this.totalBs.toFixed(2) + ' Bs?')) {
                        return;
                    }

                    this.procesando = true;

                    try {
                        const response = await fetch('procesar_venta.php', {
                            method: 'POST',
                            headers: {'Content-Type': 'application/json'},
                            body: JSON.stringify({
                                items: this.items,
                                cliente_id: this.clienteId,
                                metodo_pago: this.metodoPago,
                                total_bs: this.totalBs,
                                total_usd: this.totalUsd,
                                tasa_cambio: this.tasaCambio
                            })
                        });

                        const data = await response.json();

                        if (data.success) {
                            alert('✅ Venta registrada exitosamente\nFactura #' + data.venta_id);
                            window.open('factura_pdf.php?id=' + data.venta_id, '_blank');
                            this.items = [];
                            this.calcularTotales();
                            this.busqueda = '';
                            this.categoriaSeleccionada = null;
                            this.filtrarPorCategoria();
                            this.carritoMovilAbierto = false;
                        } else {
                            alert('❌ Error: ' + data.message);
                        }
                    } catch (error) {
                        alert('❌ Error de conexión: ' + error.message);
                    }

                    this.procesando = false;
                }
            }
        }
    </script>
</body>
</html>
