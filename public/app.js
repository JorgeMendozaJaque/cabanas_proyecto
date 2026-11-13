// Formulario de reserva con calendario. Las reglas reales las vuelve a revisar el servidor.
const MAX_NOCHES = 30;
const MAX_DIAS = 365;
const MESES = ['enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];

const form = document.getElementById('form-reserva');
const cal = document.getElementById('calendario');
const resumen = document.getElementById('resumen');
const mensaje = document.getElementById('mensaje');

// Fechas como texto YYYY-MM-DD. La aritmética se hace en UTC para que no se corra un día.
const aMs = (s) => Date.UTC(+s.slice(0, 4), +s.slice(5, 7) - 1, +s.slice(8, 10));
const sumar = (s, n) => new Date(aMs(s) + n * 864e5).toISOString().slice(0, 10);
const noches = (a, b) => Math.round((aMs(b) - aMs(a)) / 864e5);
const legible = (s) => `${+s.slice(8, 10)} de ${MESES[+s.slice(5, 7) - 1]}`;
const pesos = (n) => '$' + n.toLocaleString('es-CL');

const hoy = new Date().toLocaleDateString('sv-SE', { timeZone: 'America/Santiago' });
const limite = sumar(hoy, MAX_DIAS); // la última noche posible es la anterior a este día

let ocupadas = new Set(); // noches no disponibles
let entrada = null;
let salida = null;
let mes = hoy.slice(0, 7);

const libre = (d) => d >= hoy && d < limite && !ocupadas.has(d);
function rangoLibre(desde, hasta) {
  for (let d = desde; d < hasta; d = sumar(d, 1)) if (!libre(d)) return false;
  return true;
}
// Un día ocupado sirve como salida: esa noche no se duerme.
const salidaValida = (d) => entrada && !salida && d > entrada && noches(entrada, d) <= MAX_NOCHES && rangoLibre(entrada, d);

function mostrar(texto, tipo) {
  mensaje.textContent = texto;
  mensaje.className = 'mensaje ' + (tipo || '');
}

async function cargarDisponibilidad() {
  entrada = salida = null;
  form.personas.max = document.getElementById('capacidad').textContent = form.cabana_id.selectedOptions[0].dataset.capacidad;
  cal.setAttribute('aria-busy', 'true');
  try {
    const r = await fetch('disponibilidad.php?cabana=' + encodeURIComponent(form.cabana_id.value));
    if (!r.ok) throw new Error();
    ocupadas = new Set();
    for (const { desde, hasta } of await r.json()) {
      for (let d = desde; d < hasta; d = sumar(d, 1)) ocupadas.add(d);
    }
  } catch {
    mostrar('No pudimos cargar la disponibilidad. Recarga la página.', 'error');
  }
  cal.removeAttribute('aria-busy');
  dibujar();
}

function dibujar() {
  const [anio, m] = mes.split('-').map(Number);
  const diasDelMes = new Date(Date.UTC(anio, m, 0)).getUTCDate();
  const huecos = (new Date(aMs(mes + '-01')).getUTCDay() + 6) % 7; // la semana parte el lunes

  let html = `<div class="cal-cabecera">
    <button type="button" data-mover="-1" aria-label="Mes anterior" ${mes <= hoy.slice(0, 7) ? 'disabled' : ''}>‹</button>
    <strong>${MESES[m - 1]} ${anio}</strong>
    <button type="button" data-mover="1" aria-label="Mes siguiente" ${mes >= limite.slice(0, 7) ? 'disabled' : ''}>›</button>
  </div><div class="cal-grilla">`;
  html += ['lu', 'ma', 'mi', 'ju', 'vi', 'sá', 'do'].map((x) => `<span class="cal-semana">${x}</span>`).join('');
  html += '<span></span>'.repeat(huecos);

  for (let i = 1; i <= diasDelMes; i++) {
    const d = `${mes}-${String(i).padStart(2, '0')}`;
    const elegido = d === entrada || d === salida;
    const clases = [];
    if (ocupadas.has(d)) clases.push('ocupado');
    if (elegido) clases.push('elegido');
    else if (entrada && salida && d > entrada && d < salida) clases.push('en-rango');
    const habil = libre(d) || salidaValida(d);
    const estado = ocupadas.has(d) ? ', no disponible' : d < hoy ? '' : ', disponible';
    html += `<button type="button" class="${clases.join(' ')}" data-dia="${d}" ${habil ? '' : 'disabled'}
      aria-pressed="${elegido}" aria-label="${legible(d)}${estado}">${i}</button>`;
  }
  cal.innerHTML = html + '</div>';

  form.fecha_entrada.value = entrada || '';
  form.fecha_salida.value = salida || '';
  if (!entrada) resumen.textContent = 'Toca el día de llegada.';
  else if (!salida) resumen.textContent = `Llegada el ${legible(entrada)}. Ahora toca el día de salida.`;
  else {
    const n = noches(entrada, salida);
    const precio = +form.cabana_id.selectedOptions[0].dataset.precio;
    const total = n * precio;
    const abono = Math.ceil((total * form.dataset.abono) / 100);
    resumen.textContent = `Del ${legible(entrada)} al ${legible(salida)}: ${n} ${n === 1 ? 'noche' : 'noches'}, total ${pesos(total)}. Abono para confirmar: ${pesos(abono)}.`;
  }
}

cal.addEventListener('click', (e) => {
  const b = e.target.closest('button');
  if (!b || b.disabled) return;
  if (b.dataset.mover) {
    const [anio, m] = mes.split('-').map(Number);
    mes = new Date(Date.UTC(anio, m - 1 + +b.dataset.mover, 1)).toISOString().slice(0, 7);
  } else if (salidaValida(b.dataset.dia)) {
    salida = b.dataset.dia;
  } else {
    entrada = b.dataset.dia;
    salida = null;
  }
  dibujar();
  if (b.dataset.dia) cal.querySelector(`[data-dia="${b.dataset.dia}"]`)?.focus();
});

// Quita al escribir (o pegar) los caracteres que el campo no acepta. El servidor lo vuelve a validar.
const FILTROS = {
  letras: /[^\p{L} '.\-]/gu,
  telefono: /[^0-9+]|(?!^)\+/g, // + solo al inicio
  correo: /\s/g,
};
form.addEventListener('input', (e) => {
  const campo = e.target;
  const filtro = FILTROS[campo.dataset.solo];
  if (!filtro) return;
  let limpio = campo.value.replace(filtro, '');
  if (campo.dataset.solo === 'telefono') limpio = limpio.match(/^\+?[0-9]{0,15}/)[0]; // máximo 15 dígitos
  if (limpio === campo.value) return;
  if (campo.type === 'email') { campo.value = limpio; return; } // los campos email no permiten mover el cursor
  const cursor = campo.selectionStart - (campo.value.slice(0, campo.selectionStart).length - campo.value.slice(0, campo.selectionStart).replace(filtro, '').length);
  campo.value = limpio;
  campo.setSelectionRange(cursor, cursor);
});
// Personas: los campos numéricos aceptan "e", "-", "." y ","; aquí solo enteros.
form.personas.addEventListener('keydown', (e) => {
  if (['e', 'E', '+', '-', '.', ','].includes(e.key)) e.preventDefault();
});

form.cabana_id.addEventListener('change', () => {
  mostrar('');
  cargarDisponibilidad();
});

form.addEventListener('submit', async (e) => {
  e.preventDefault();
  if (!entrada || !salida) return mostrar('Elige en el calendario el día de llegada y el de salida.', 'error');
  const boton = form.querySelector('button[type=submit]');
  boton.disabled = true;
  mostrar('Enviando…');
  try {
    const r = await (await fetch('guardar-reserva.php', { method: 'POST', body: new FormData(form) })).json();
    if (r.ok) {
      location.href = 'confirmacion.php';
      return;
    } else {
      if (r.ocupado) await cargarDisponibilidad();
      mostrar(r.error, 'error');
    }
  } catch {
    mostrar('No pudimos enviar la solicitud. Revisa tu conexión e intenta de nuevo.', 'error');
  }
  boton.disabled = false;
});

cargarDisponibilidad();
