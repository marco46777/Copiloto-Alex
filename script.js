const texto = document.getElementById("texto");
const mensaje = document.getElementById("mensaje");
const boton = document.getElementById("enviar");
const chat = document.getElementById("chat");


/* ==================================================
   MENSAJE INICIAL (efecto de escritura en tarjeta 1)
================================================== */

const contenidoInicial = texto.textContent;
texto.textContent = "";

let i = 0;

function escribir() {
    if (i < contenidoInicial.length) {
        texto.textContent += contenidoInicial.charAt(i);
        i++;
        setTimeout(escribir, 40);
    }
}

escribir();


/* ==================================================
   UTILIDADES
================================================== */

function horaActual() {
    return new Date().toLocaleTimeString("es-CO", {
        hour: "2-digit",
        minute: "2-digit"
    });
}

function esperar(ms) {
    return new Promise(resolve => setTimeout(resolve, ms));
}

function limpiarNombreArchivo(nombre) {
    return String(nombre)
        .replace(/[<>:"/\\|?*]/g, "_")
        .replace(/\s+/g, "_")
        .substring(0, 100);
}


/* ==================================================
   AGREGAR MENSAJE AL CHAT
   tipo: "usuario" o "alex"
   Devuelve el <p> para poder escribir dentro de él
================================================== */

function agregarMensaje(contenido, tipo) {

    const div = document.createElement("div");
    div.className = "mensaje-chat " + tipo;

    const burbuja = document.createElement("div");
    burbuja.className = "burbuja";

    const p = document.createElement("p");
    p.textContent = contenido;          // textContent evita inyección de HTML

    const hora = document.createElement("span");
    hora.className = "hora";
    hora.textContent = horaActual();

    burbuja.appendChild(p);
    burbuja.appendChild(hora);
    div.appendChild(burbuja);
    chat.appendChild(div);

    chat.scrollTop = chat.scrollHeight;

    return p;
}


/* ==================================================
   ALEX ESCRIBE (burbuja con efecto de tipeo)
================================================== */

async function escribirTexto(textoCompleto, velocidad = 25) {

    const p = agregarMensaje("", "alex");

    for (let n = 0; n < textoCompleto.length; n++) {
        p.textContent += textoCompleto.charAt(n);
        chat.scrollTop = chat.scrollHeight;
        await esperar(velocidad);
    }
}


/* ==================================================
   INDICADOR "ESCRIBIENDO..."
================================================== */

function mostrarPensando() {
    const p = agregarMensaje("Alex está pensando...", "alex");
    const burbuja = p.closest(".mensaje-chat");
    burbuja.classList.add("pensando");
    return burbuja;
}


/* ==================================================
   DESCARGAR ARCHIVO
================================================== */

function descargarArchivo(blob, nombre) {

    const url = URL.createObjectURL(blob);
    const enlace = document.createElement("a");

    enlace.href = url;
    enlace.download = nombre;

    document.body.appendChild(enlace);
    enlace.click();
    enlace.remove();

    setTimeout(() => URL.revokeObjectURL(url), 1000);
}


/* ==================================================
   PEDIR ARCHIVO A UN PHP (pdf / excel)
================================================== */

async function pedirArchivo(url, datos) {

    const respuesta = await fetch(url, {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify(datos)
    });

    if (!respuesta.ok) {
        const error = await respuesta.text();
        throw new Error(url + " respondió HTTP " + respuesta.status + ": " + error);
    }

    const archivo = await respuesta.blob();

    if (archivo.size === 0) {
        throw new Error("El archivo recibido de " + url + " está vacío.");
    }

    return archivo;
}


/* ==================================================
   ENVIAR
================================================== */

boton.addEventListener("click", async function () {

    const solicitud = mensaje.value.trim();

    if (solicitud === "") {
        alert("Por favor, escribe una solicitud.");
        return;
    }

    /* 1. Mostrar lo que escribió el usuario como chat */
    agregarMensaje(solicitud, "usuario");
    mensaje.value = "";

    boton.disabled = true;
    boton.textContent = "Enviando...";

    const pensando = mostrarPensando();

    try {

        /* 2. Enviar a procesar.php */
        const respuesta = await fetch("procesar.php", {
            method: "POST",
            headers: { "Content-Type": "application/json" },
            body: JSON.stringify({ solicitud: solicitud })
        });

        const textoRespuesta = await respuesta.text();
        console.log("Respuesta de procesar.php:", textoRespuesta);

        if (!respuesta.ok) {
            throw new Error(
                "procesar.php respondió HTTP " + respuesta.status + ": " + textoRespuesta
            );
        }

        let resultado;

        try {
            resultado = JSON.parse(textoRespuesta);
        } catch (e) {
            throw new Error("procesar.php no devolvió JSON válido.");
        }

        console.log("Resultado de Alex:", resultado);

        pensando.remove();

        /* 3. PDF */
        if (resultado.tipo === "pdf") {

            await escribirTexto(
                "He preparado el contenido. Ahora estoy creando tu PDF...", 25
            );

            const archivoPDF = await pedirArchivo("generar_pdf.php", {
                titulo: resultado.titulo,
                elementos: resultado.elementos
            });

            descargarArchivo(
                archivoPDF,
                limpiarNombreArchivo(resultado.titulo || "COPILOTO_ALEX") + ".pdf"
            );

            await escribirTexto("PDF generado correctamente. ✅", 30);
        }

        /* 4. EXCEL */
        else if (resultado.tipo === "excel") {

            await escribirTexto(
                "He preparado los datos. Ahora estoy creando tu Excel...", 25
            );

            const archivoExcel = await pedirArchivo("generar_excel.php", {
                titulo: resultado.titulo,
                columnas: resultado.columnas,
                datos: resultado.datos
            });

            descargarArchivo(
                archivoExcel,
                limpiarNombreArchivo(resultado.titulo || "COPILOTO_ALEX") + ".xls"
            );

            await escribirTexto("Excel generado correctamente. ✅", 30);
        }

        /* 5. RESPUESTA NORMAL */
        else {
            await escribirTexto(
                resultado.respuesta || "No pude generar una respuesta.", 20
            );
        }

    } catch (error) {

        console.error("ERROR COMPLETO:", error);

        pensando.remove();
        await escribirTexto("Ocurrió un error: " + error.message, 20);

    } finally {

        boton.disabled = false;
        boton.textContent = "Enviar";
        mensaje.focus();
    }
});


/* ==================================================
   ENTER PARA ENVIAR
================================================== */

mensaje.addEventListener("keydown", function (evento) {

    if (evento.key === "Enter" && !evento.shiftKey) {

        evento.preventDefault();

        if (!boton.disabled) {
            boton.click();
        }
    }
});