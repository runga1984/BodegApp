<?php
// header.php - Header unificado para todas las páginas
$current_page = basename($_SERVER['PHP_SELF']);
$is_dashboard = ($current_page == 'index.php');
?>
<nav class="navbar navbar-dark navbar-expand-lg mb-4" style="background: linear-gradient(135deg, #198754 0%, #146c43 100%) !important;">
    <div class="container-fluid">
        <?php if (!$is_dashboard): ?>
            <a class="navbar-brand" href="index.php">
                ← Volver al Dashboard
            </a>
        <?php else: ?>
            <span class="navbar-brand">🏪 Nine Market</span>
        <?php endif; ?>
        
        <div class="d-flex align-items-center">
            <span class="text-white me-3">👤 <?= htmlspecialchars($_SESSION['user_name'] ?? 'Usuario') ?></span>
            <a href="logout.php" class="btn btn-outline-light btn-sm">Salir</a>
        </div>
    </div>
</nav>