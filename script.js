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
/* ==================================================
   VOZ DE ALEX: grave y ligeramente robótica
   Pega este archivo COMPLETO al final de tu JS
   (reemplaza los dos bloques anteriores: voz + saludo).
================================================== */

/* ---------- AJUSTES (cámbialos a tu gusto) ---------- */
const AJUSTE_VOZ = {
    tono: 0.55,        // 0.1 a 2. Más bajo = más grueso. Por debajo de 0.4 puede distorsionar
    velocidad: 0.93,   // 1 es normal. Un poco más lento da un aire más "máquina"
    volumen: 1,
    largoMinimoFrase: 18   // las frases se dicen por tramos cortos, con pausas pequeñas (efecto robótico suave)
};

const vozAlex = {
    voz: null,
    activa: ("speechSynthesis" in window)
};

// Nombres de voces masculinas habituales en español (Edge, Chrome, Windows, Android, macOS)
const REGEX_VOZ_MASCULINA =
    /(pablo|ra[uú]l|jorge|diego|juan|carlos|miguel|enrique|andr[eé]s|[aá]lvaro|pedro|jaime|alberto|male|hombre|masculin)/i;

function elegirVozAlex() {

    if (!vozAlex.activa) return;

    const voces = speechSynthesis.getVoices();
    const enEspanol = voces.filter(v => /^es([-_]|$)/i.test(v.lang));

    // Preferimos español latino y luego cualquier español
    const latino = enEspanol.filter(v => /^es[-_](CO|MX|US|419|AR|CL|PE|VE)/i.test(v.lang));
    const candidatas = latino.concat(enEspanol);

    vozAlex.voz =
        candidatas.find(v => REGEX_VOZ_MASCULINA.test(v.name)) ||
        enEspanol[0] ||
        null;
}

if (vozAlex.activa) {
    elegirVozAlex();
    // Las voces se cargan de forma asíncrona en varios navegadores
    speechSynthesis.onvoiceschanged = elegirVozAlex;
}

/* Divide el texto en tramos cortos (por comas, puntos, etc.).
   Cada tramo se dice por separado y deja una pausa breve entre ellos. */
function trocearParaVoz(texto) {

    const partes = texto.match(/[^.,;:!?]+[.,;:!?]*/g) || [texto];
    const tramos = [];
    let acumulado = "";

    partes.forEach(function (parte) {
        acumulado += (acumulado ? " " : "") + parte.trim();
        if (acumulado.length >= AJUSTE_VOZ.largoMinimoFrase) {
            tramos.push(acumulado);
            acumulado = "";
        }
    });

    if (acumulado) tramos.push(acumulado);

    return tramos;
}

/* Dice un texto con la voz de Alex.
   opciones.alEmpezar / opciones.alError se asocian al primer tramo. */
function decirConVozAlex(texto, opciones) {

    opciones = opciones || {};

    trocearParaVoz(texto).forEach(function (tramo, indice) {

        const locucion = new SpeechSynthesisUtterance(tramo);

        if (vozAlex.voz) {
            locucion.voice = vozAlex.voz;
            locucion.lang = vozAlex.voz.lang;
        } else {
            locucion.lang = "es-CO";
        }

        locucion.pitch = AJUSTE_VOZ.tono;
        locucion.rate = AJUSTE_VOZ.velocidad;
        locucion.volume = AJUSTE_VOZ.volumen;

        if (indice === 0) {
            if (opciones.alEmpezar) locucion.onstart = opciones.alEmpezar;
            if (opciones.alError) locucion.onerror = opciones.alError;
        }

        speechSynthesis.speak(locucion);
    });
}

function hablar(textoCompleto) {

    if (!vozAlex.activa) return;

    // Limpiar lo que no se debe leer en voz alta: enlaces, emojis, símbolos de lista
    const limpio = String(textoCompleto)
        .replace(/https?:\/\/\S+/g, "el enlace")
        .replace(/[\u{1F300}-\u{1FAFF}\u{2600}-\u{27BF}\u{FE0F}]/gu, "")
        .replace(/^\s*[-•*]\s+/gm, "")
        .replace(/\s+/g, " ")
        .trim();

    if (limpio === "") return;

    speechSynthesis.cancel();   // corta lo anterior para no acumular voces
    decirConVozAlex(limpio);
}

// Envolver escribirTexto: primero habla, luego ejecuta el efecto de escritura original
const escribirTextoOriginal = escribirTexto;

escribirTexto = async function (textoCompleto, velocidad = 25) {
    hablar(textoCompleto);
    // Escritura un poco más lenta para que el texto vaya al ritmo de la voz
    return escribirTextoOriginal(textoCompleto, Math.max(velocidad, 45));
};


/* ==================================================
   SALUDO INICIAL HABLADO
   Lee el mismo texto que escribe la tarjeta 1 (contenidoInicial),
   así que sirve aunque el PHP cambie el nombre.
================================================== */

(function () {

    if (!vozAlex.activa) return;

    const saludo = contenidoInicial
        .replace(/\.,/g, ",")        // "bienvenido.," -> "bienvenido,"
        .replace(/\s+/g, " ")
        .trim();

    if (saludo === "") return;

    let yaHablo = false;
    const eventos = ["pointerdown", "keydown", "touchstart"];

    function decirSaludo() {

        if (yaHablo) return;

        decirConVozAlex(saludo, {
            alEmpezar: function () { yaHablo = true; },
            // Los navegadores bloquean la voz si el usuario aún no ha tocado la página.
            // En ese caso esperamos la primera interacción y entonces lo decimos.
            alError: function (e) {
                if (e.error === "not-allowed") {
                    speechSynthesis.cancel();
                    esperarInteraccion();
                }
            }
        });
    }

    function esperarInteraccion() {

        function alInteractuar() {
            eventos.forEach(ev => document.removeEventListener(ev, alInteractuar));
            decirSaludo();
        }

        eventos.forEach(ev => document.addEventListener(ev, alInteractuar));
    }

    // Pequeña espera para que el navegador alcance a cargar la lista de voces
    setTimeout(decirSaludo, 600);

})();