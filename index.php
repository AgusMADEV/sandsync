<?php
session_start();

// Conectar a SQLite
try {
    $db = new PDO('sqlite:' . __DIR__ . '/calendar.db');
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (Exception $e) {
    die("Error al conectar a la base de datos: " . $e->getMessage());
}

// Crear tablas si no existen
$db->exec("CREATE TABLE IF NOT EXISTS users (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT,
    email TEXT,
    username TEXT UNIQUE,
    password TEXT
)");
$db->exec("CREATE TABLE IF NOT EXISTS hours (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER,
    date TEXT,
    hours REAL,
    FOREIGN KEY(user_id) REFERENCES users(id)
)");

// Insertar usuario inicial si no existe
$check = $db->prepare("SELECT id FROM users WHERE username = ?");
$check->execute(['agusmadev']);
if (!$check->fetch()) {
    $hash = password_hash('agusmadev', PASSWORD_DEFAULT);
    $insert = $db->prepare("INSERT INTO users (name,email,username,password) VALUES (?,?,?,?)");
    $insert->execute(['Agustín Morcillo Aguado','info@agusmadev.es','agusmadev',$hash]);
}

// Manejo de logout
if (isset($_GET['action']) && $_GET['action'] === 'logout') {
    session_destroy();
    header('Location: index.php');
    exit;
}

// Manejo de login
if (isset($_POST['login'])) {
    $stmt = $db->prepare("SELECT * FROM users WHERE username = ?");
    $stmt->execute([$_POST['username']]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($user && password_verify($_POST['password'], $user['password'])) {
        $_SESSION['user_id'] = $user['id'];
        header('Location: index.php');
        exit;
    } else {
        $error = 'Usuario o contraseña inválidos';
    }
}

// Manejo de registro
if (isset($_POST['register'])) {
    $hash = password_hash($_POST['password'], PASSWORD_DEFAULT);
    $stmt = $db->prepare("INSERT INTO users (name,email,username,password) VALUES (?,?,?,?)");
    try {
        $stmt->execute([$_POST['name'],$_POST['email'],$_POST['username'],$hash]);
        $_SESSION['user_id'] = $db->lastInsertId();
        header('Location: index.php');
        exit;
    } catch (Exception $e) {
        $error = 'Error al registrar: ' . $e->getMessage();
    }
}

// Verificar sesión
if (!isset($_SESSION['user_id'])) {
    // Mostrar login/register
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login / Register</title>
    <link rel="stylesheet" href="styles.css">
</head>
<body>
<div class="container">
    <?php if (isset($error)): ?><p class="error"><?php echo $error; ?></p><?php endif; ?>
    <div class="box">
        <h2>Login</h2>
        <form method="post">
            <input type="text" name="username" placeholder="Usuario" required>
            <input type="password" name="password" placeholder="Contraseña" required>
            <button type="submit" name="login">Entrar</button>
        </form>
    </div>
    <div class="box">
        <h2>Registro</h2>
        <form method="post">
            <input type="text" name="name" placeholder="Nombre completo" required>
            <input type="email" name="email" placeholder="Email" required>
            <input type="text" name="username" placeholder="Usuario" required>
            <input type="password" name="password" placeholder="Contraseña" required>
            <button type="submit" name="register">Registrar</button>
        </form>
    </div>
</div>
</body>
</html>
<?php
    exit;
}

// Usuario autenticado
$currentUserId = $_SESSION['user_id'];
// Determinar si es admin (agusmadev)
$stmt = $db->prepare("SELECT username FROM users WHERE id = ?");
$stmt->execute([$currentUserId]);
$currentUsername = $stmt->fetchColumn();
$isAdmin = $currentUsername === 'agusmadev';

// Si admin y pide ver calendario de otro usuario
$viewUserId = $currentUserId;
if ($isAdmin && isset($_GET['user_id'])) {
    $viewUserId = (int)$_GET['user_id'];
}

// Manejo de guardado del calendario
if (isset($_POST['save_calendar'])) {
    $db->prepare("DELETE FROM hours WHERE user_id = ? AND date BETWEEN '2025-03-01' AND '2025-06-30'")
       ->execute([$currentUserId]);
    if (!empty($_POST['hours']) && is_array($_POST['hours'])) {
        $ins = $db->prepare("INSERT INTO hours (user_id,date,hours) VALUES (?,?,?)");
        foreach ($_POST['hours'] as $date => $hrs) {
            $h = floatval($hrs);
            if ($h > 0) {
                $ins->execute([$currentUserId, $date, $h]);
            }
        }
    }
    $params = [];
    if ($isAdmin && $viewUserId !== $currentUserId) $params[] = 'user_id=' . $viewUserId;
    $params[] = 'saved=1';
    header('Location: index.php?' . implode('&', $params));
    exit;
}

// Obtener datos de horas para visualizar
$data = [];
$stmt = $db->prepare("SELECT date,hours FROM hours WHERE user_id = ?");
$stmt->execute([$viewUserId]);
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $data[$row['date']] = $row['hours'];
}

// Cálculo total horas
$total = array_sum($data);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Calendario de Horas</title>
    <link rel="stylesheet" href="styles.css">
</head>
<body>
<div class="container">
    <header>
        <h1>Hola, <?php echo htmlspecialchars($currentUsername); ?></h1>
        <p>Total de horas: <span id="totalHours"><?php echo $total; ?></span></p>
        <a href="?action=logout" class="btn">Cerrar sesión</a>
    </header>

    <?php if ($isAdmin): ?>
    <section class="users-list">
        <h2>Calendarios de usuarios</h2>
        <ul>
        <?php
            $uStmt = $db->query("SELECT DISTINCT u.id,u.username FROM users u JOIN hours h ON u.id=h.user_id");
            foreach ($uStmt->fetchAll(PDO::FETCH_ASSOC) as $u) {
                echo '<li><a href="?user_id='.$u['id'].'">'.htmlspecialchars($u['username']).'</a></li>';
            }
        ?>
        </ul>
    </section>
    <?php endif; ?>

    <?php if (isset($_GET['saved'])): ?>
        <div class="flash-message">Calendario guardado correctamente.</div>
    <?php endif; ?>

    <form method="post" id="calendarForm">
        <input type="hidden" name="save_calendar" value="1">
    <?php
    function renderCalendar($month, $year, $data, $editable) {
        $firstDay = new DateTime("$year-$month-01");
        $startDay = (int)$firstDay->format('N');
        $daysInMonth = (int)$firstDay->format('t');
        echo "<h2>".strftime('%B %Y',$firstDay->getTimestamp())."</h2>";
        echo "<table class=\"calendar\"><tr>";
        foreach (['Lun','Mar','Mié','Jue','Vie','Sáb','Dom'] as $d) echo "<th>$d</th>";
        echo "</tr><tr>";
        for ($i=1; $i < $startDay; $i++) echo "<td></td>";
        for ($day=1; $day <= $daysInMonth; $day++) {
            $date = sprintf('%04d-%02d-%02d',$year,$month,$day);
            $val = $data[$date] ?? '';
            echo '<td><div class="day-number">'.$day.'</div>';
            if ($editable) {
                echo "<input class=\"hour-input\" type=number step=0.25 min=0 name='hours[$date]' value='$val'>";
            } else {
                echo htmlspecialchars($val);
            }
            echo '</td>';
            if ((($day + $startDay -1) % 7) == 0) echo "</tr><tr>";
        }
        echo "</tr></table>";
    }
    for ($m=3; $m<=6; $m++) {
        renderCalendar($m,2025,$data, $viewUserId === $currentUserId);
    }
    ?>
    </form>

    <?php if ($viewUserId === $currentUserId): ?>
        <button class="save-btn-floating" type="button" onclick="document.getElementById('calendarForm').submit()">💾</button>
    <?php endif; ?>
</div>

<script>

document.addEventListener('DOMContentLoaded', function() {
    const inputs = document.querySelectorAll('.hour-input');
    function updateTotal() {
        let total = 0;
        inputs.forEach(input => {
            const val = parseFloat(input.value);
            if (!isNaN(val)) total += val;
        });
        document.getElementById('totalHours').textContent = total;
    }
    inputs.forEach(input => input.addEventListener('input', updateTotal));
    updateTotal();
    // Transición suave para mensaje de guardado
    const flash = document.querySelector('.flash-message');
    if (flash) {
        flash.style.transition = 'opacity 0.5s ease, transform 0.5s ease';
        setTimeout(() => {
            flash.style.opacity = '0';
            flash.style.transform = 'translateY(10px)';
            setTimeout(() => flash.remove(), 500);
        }, 3000);
    }
});

</script>
</body>
</html>
