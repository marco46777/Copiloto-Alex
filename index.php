<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>COPILOTO</title>
    <link rel="stylesheet" href="style.css">
<link rel="icon" type="image/png" href="/imag/robo.jpg">
</head>

<body>

    <header>
        <h1>ALEX TECH</h1>
    </header>

    <main>

        <!-- TARJETA 1: BIENVENIDA -->
        <div class="tarjeta">
            <img src="/imag/robot.gif" class="logo" alt="Robot Alex">
            <h2>Hola 👋</h2>

            <?php
                $nombre = "bienvenido";
                echo "<p id='texto'>Hola, $nombre., soy Alex Tech. ¿Qué quieres hacer hoy?</p>";
            ?>
        </div>

        <!-- TARJETA 2: CHAT -->
        <div class="tarjeta1">

            <!-- AQUÍ APARECEN LOS MENSAJES -->
            <div id="chat"></div>

            <!-- CAJA PARA ESCRIBIR -->
            <div class="zona-escritura">
                <input type="text" id="mensaje" placeholder="Por favor escriba un mensaje..." autocomplete="off">
                <button id="enviar">Enviar</button>
            </div>

        </div>

    </main>

    <footer>
        <p>&copy; <?php echo date("Y"); ?></p>
    </footer>

    <script src="script.js"></script>

</body>
</html>