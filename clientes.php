<?php
require_once 'config.php';
requireLogin();

// Procesar acciones
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'crear') {
        $stmt = $pdo->prepare("
            INSERT INTO clientes (nombre, telefono, direccion, clasificacion, limite_credito) 
            VALUES (?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $_POST['nombre'],
            $_POST['telefono'],
            $_POST['direccion'],
            $_POST['clasificacion'],
            $_POST['limite_credito']
        ]);
        header('Location: clientes.php?success=1');
        exit;
    }
    
    if ($_POST['action'] === 'editar') {
        $stmt = $pdo->prepare("
            UPDATE clientes 
            SET nombre=?, telefono=?, direccion=?, clasificacion=?, limite_credito=?
            WHERE id=?
        ");
        $stmt->execute([
            $_POST['nombre'],
            $_POST['telefono'],
            $_POST['direccion'],
            $_POST['clasificacion'],
            $_POST['limite_credito'],
            $_POST['id']
        ]);
        header('Location: clientes.php?success=2');
        exit;
    }
}

$clientes = $pdo->query("
    SELECT c.*, 
           COUNT(DISTINCT v.id) as total_ventas,
           IFNULL(SUM(v.total_bs), 0) as total_comprado,
           (SELECT COUNT(*) FROM creditos cr WHERE cr.cliente_id = c.id) as creditos_activos
    FROM clientes c
    LEFT JOIN ventas v ON c.id = v.cliente_id
    GROUP BY c.id
    ORDER BY c.nombre
")->fetchAll();
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Clientes - Nine Market</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body { background-color: #f8f9fa; }
        [x-cloak] { display: none !important; }
    </style>
</head>
<body>
    <?php include 'header.php'; ?>

    <div class="container-fluid" x-data="clientesApp()" x-cloak>
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h2>👥 Gestión de Clientes</h2>
            <button class="btn btn-success" @click="abrirModalNuevo()">
                ➕ Nuevo Cliente
            </button>
        </div>

        <?php if (isset($_GET['success'])): ?>
        <div class="alert alert-success alert-dismissible fade show">
            <?php
            $messages = [1 => 'Cliente creado', 2 => 'Cliente actualizado'];
            echo $messages[$_GET['success']];
            ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php endif; ?>

        <div class="card shadow-sm">
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-hover">
                        <thead>
                            <tr>
                                <th>Nombre</th>
                                <th>Teléfono</th>
                                <th>Clasificación</th>
                                <th>Límite Crédito</th>
                                <th>Total Ventas</th>
                                <th>Total Comprado</th>
                                <th>Créditos Activos</th>
                                <th>Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($clientes as $c): ?>
                            <tr>
                                <td><?= htmlspecialchars($c['nombre']) ?></td>
                                <td><?= htmlspecialchars($c['telefono'] ?: '-') ?></td>
                                <td>
                                    <span class="badge bg-<?= $c['clasificacion'] === 'A' ? 'success' : ($c['clasificacion'] === 'B' ? 'warning' : 'secondary') ?>">
                                        <?= $c['clasificacion'] ?>
                                    </span>
                                </td>
                                <td>$<?= number_format($c['limite_credito'], 2) ?></td>
                                <td><?= $c['total_ventas'] ?></td>
                                <td><?= formatMoney($c['total_comprado']) ?></td>
                                <td><?= $c['creditos_activos'] ?></td>
                                <td>
                                    <?php if ($c['id'] != 1): // No editar Consumidor Final ?>
                                    <button class="btn btn-sm btn-outline-primary" 
                                            @click='editarCliente(<?= json_encode($c) ?>)'>
                                        ✏️
                                    </button>
                                    <?php endif; ?>
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
             x-cloak
             class="modal fade"
             :class="{'show d-block': modalNuevo || modalEditar}"
             style="background: rgba(0,0,0,0.5)"
             @click.self="cerrarModal()">
            <div class="modal-dialog">
                <div class="modal-content">
                    <form method="POST">
                        <input type="hidden" name="action" :value="modalEditar ? 'editar' : 'crear'">
                        <input type="hidden" name="id" x-model="cliente.id">
                        
                        <div class="modal-header">
                            <h5 class="modal-title" x-text="modalEditar ? 'Editar Cliente' : 'Nuevo Cliente'"></h5>
                            <button type="button" class="btn-close" @click="cerrarModal()"></button>
                        </div>
                        
                        <div class="modal-body">
                            <div class="mb-3">
                                <label class="form-label">Nombre *</label>
                                <input type="text" name="nombre" x-model="cliente.nombre" class="form-control" required>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Teléfono</label>
                                <input type="text" name="telefono" x-model="cliente.telefono" class="form-control">
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Dirección</label>
                                <textarea name="direccion" x-model="cliente.direccion" class="form-control" rows="2"></textarea>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Clasificación</label>
                                <select name="clasificacion" x-model="cliente.clasificacion" class="form-select">
                                    <option value="A">A - Premium</option>
                                    <option value="B">B - Regular</option>
                                    <option value="C">C - Básico</option>
                                </select>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Límite de Crédito (USD)</label>
                                <input type="number" name="limite_credito" x-model="cliente.limite_credito" 
                                       class="form-control" step="0.01" min="0">
                            </div>
                        </div>
                        
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" @click="cerrarModal()">Cancelar</button>
                            <button type="submit" class="btn btn-success">Guardar</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/alpinejs@3.13.10/dist/cdn.min.js" defer></script>
    <script>
        function clientesApp() {
            return {
                modalNuevo: false,
                modalEditar: false,
                cliente: {
                    id: '',
                    nombre: '',
                    telefono: '',
                    direccion: '',
                    clasificacion: 'C',
                    limite_credito: 0
                },

                init() {
                    // Asegurar que los modales estén cerrados al iniciar
                    this.modalNuevo = false;
                    this.modalEditar = false;
                },

                abrirModalNuevo() {
                    this.cliente = {
                        id: '', nombre: '', telefono: '', direccion: '',
                        clasificacion: 'C', limite_credito: 0
                    };
                    this.modalNuevo = true;
                    this.modalEditar = false;
                },

                editarCliente(cli) {
                    this.cliente = {...cli};
                    this.modalEditar = true;
                    this.modalNuevo = false;
                },

                cerrarModal() {
                    this.modalNuevo = false;
                    this.modalEditar = false;
                    this.cliente = {
                        id: '', nombre: '', telefono: '', direccion: '',
                        clasificacion: 'C', limite_credito: 0
                    };
                }
            }
        }
    </script>
</body>
</html>