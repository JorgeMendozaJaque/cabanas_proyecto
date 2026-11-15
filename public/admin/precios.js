// Mini calendario de la pestaña Precios: muestra reservas, días cerrados y precios especiales de la cabaña
// elegida, y marca en vivo las fechas del formulario. Solo ayuda visual: el servidor valida al guardar.
const datos = JSON.parse(document.getElementById('datos-precios').textContent);
const form = document.getElementById('form-precio-especial');
const cal = document.getElementById('mini-cal');
const aviso = document.getElementById('aviso-precio');
const MESES = ['enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];

// Fechas como texto YYYY-MM-DD, aritmética en UTC para que no se corra un día (igual que app.js).
const aMs = (s) => Date.UTC(+s.slice(0, 4), +s.slice(5, 7) - 1, +s.slice(8, 10));
const sumar = (s, n) => new Date(aMs(s) + n * 864e5).toISOString().slice(0, 10);
const hoy = datos.hoy;
let mes = hoy.slice(0, 7);

// Días de la cabaña elegida. Una reserva ocupa las noches hasta el día antes de su salida; cerrados y precios incluyen el último día.
function diasDe(lista, incluyeFin) {
  const dias = new Set();
  for (const [cabana, desde, hasta] of lista) {
    if (cabana !== +form.cabana_id.value) continue;
    for (let d = desde; incluyeFin ? d <= hasta : d < hasta; d = sumar(d, 1)) dias.add(d);
  }
  return dias;
}

function dibujar() {
  const reservado = diasDe(datos.reservas, false);
  const cerrado = diasDe(datos.cerrados, true);
  const especial = diasDe(datos.precios, true);
  const desde = form.fecha_inicio.value;
  const hasta = form.fecha_fin.value;
  const elegido = (d) => d === desde || (desde && hasta && d >= desde && d <= hasta);

  const [anio, m] = mes.split('-').map(Number);
  const diasDelMes = new Date(Date.UTC(anio, m, 0)).getUTCDate();
  const huecos = (new Date(aMs(mes + '-01')).getUTCDay() + 6) % 7; // la semana parte el lunes
  let html = `<div class="cal-cabecera">
    <button type="button" data-mover="-1" aria-label="Mes anterior">‹</button>
    <strong>${MESES[m - 1]} ${anio}</strong>
    <button type="button" data-mover="1" aria-label="Mes siguiente">›</button>
  </div><div class="cal-grilla">`;
  html += ['lu', 'ma', 'mi', 'ju', 'vi', 'sá', 'do'].map((x) => `<span class="cal-semana">${x}</span>`).join('');
  html += '<span></span>'.repeat(huecos);
  for (let i = 1; i <= diasDelMes; i++) {
    const d = `${mes}-${String(i).padStart(2, '0')}`;
    const clases = [];
    const nota = [];
    if (reservado.has(d)) { clases.push('ocupado'); nota.push('con huéspedes'); }
    if (cerrado.has(d)) { clases.push('cerrado'); nota.push('cerrado'); }
    if (especial.has(d) && d < hoy) { clases.push('especial pasado'); nota.push('tuvo precio especial'); }
    else if (especial.has(d)) { clases.push('especial'); nota.push('ya tiene precio especial'); }
    if (elegido(d)) clases.push(reservado.has(d) || especial.has(d) ? 'elegido choque' : 'elegido');
    html += `<button type="button" class="${clases.join(' ')}" data-dia="${d}" ${d < hoy ? 'disabled' : ''}
      aria-pressed="${!!elegido(d)}" aria-label="${i} de ${MESES[m - 1]}${nota.length ? ', ' + nota.join(', ') : ''}">${i}</button>`;
  }
  cal.innerHTML = html + '</div>';

  // Aviso según lo que hay dentro del rango elegido.
  let conHuespedes = 0;
  let cruces = 0;
  if (desde && hasta && hasta >= desde) {
    for (let d = desde; d <= hasta; d = sumar(d, 1)) {
      if (reservado.has(d)) conHuespedes++;
      if (especial.has(d)) cruces++;
    }
  }
  if (cruces) mostrar('Se cruza con otro precio especial: así no se puede guardar. Bórralo o elige otras fechas.', 'error');
  else if (conHuespedes) mostrar(`Ojo: hay huéspedes en ${conHuespedes} ${conHuespedes === 1 ? 'noche' : 'noches'} de ese rango. Su precio no cambia; el precio especial solo aplica a reservas nuevas.`, 'error');
  else if (desde && hasta && hasta >= desde) mostrar('Fechas sin huéspedes ni otros precios especiales.', 'ok');
  else mostrar(desde ? 'Ahora toca el último día.' : 'Toca el primer día en el calendario o escríbelo en el formulario.');
}

function mostrar(texto, tipo) {
  aviso.textContent = texto;
  aviso.className = 'mensaje ' + (tipo || '');
}

cal.addEventListener('click', (e) => {
  const b = e.target.closest('button');
  if (!b || b.disabled) return;
  if (b.dataset.mover) {
    const [anio, m] = mes.split('-').map(Number);
    mes = new Date(Date.UTC(anio, m - 1 + +b.dataset.mover, 1)).toISOString().slice(0, 7);
  } else {
    const d = b.dataset.dia;
    // Primer toque: "desde". Segundo: "hasta" (si es antes que "desde", vuelve a empezar).
    if (!form.fecha_inicio.value || form.fecha_fin.value || d < form.fecha_inicio.value) {
      form.fecha_inicio.value = d;
      form.fecha_fin.value = '';
    } else {
      form.fecha_fin.value = d;
    }
  }
  dibujar();
});

// Escribir las fechas a mano también mueve el calendario al mes de "desde".
form.addEventListener('input', (e) => {
  if (e.target === form.fecha_inicio && form.fecha_inicio.value) mes = form.fecha_inicio.value.slice(0, 7);
  dibujar();
});
form.cabana_id.addEventListener('change', dibujar);

// Al cambiar el precio general siempre pide confirmar. Además alerta si una promo vigente (más barata que el
// general actual) deja de serlo, porque los precios especiales son montos fijos y no se ajustan solos.
const pesos = (n) => '$' + n.toLocaleString('es-CL');
const fecha = (s) => s.split('-').reverse().join('-');
for (const base of document.querySelectorAll('.form-precio')) {
  base.addEventListener('submit', (e) => {
    const actual = +base.dataset.actual;
    const nuevo = +base.precio.value.replace(/[\s.$]/g, '');
    if (!nuevo || nuevo === actual) return; // sin cambio no pregunta; un precio inválido lo rechaza el servidor
    const promosPerdidas = datos.precios.filter(([cabana, , hasta, precio]) =>
      cabana === +base.cabana_id.value && hasta >= hoy && precio < actual && precio >= nuevo); // solo promos que siguen vigentes
    let pregunta = `¿Cambiar el precio general de ${pesos(actual)} a ${pesos(nuevo)}?`;
    if (promosPerdidas.length) {
      const lineas = promosPerdidas.map(([, desde, hasta, precio]) => `• ${fecha(desde)} al ${fecha(hasta)}: ${pesos(precio)} por noche`);
      pregunta = `⚠ Ojo: con ${pesos(nuevo)} ${promosPerdidas.length === 1 ? 'esta promo deja' : 'estas promos dejan'} de ser oferta ` +
        `(quedan iguales o más caras que el precio general):\n\n${lineas.join('\n')}\n\n` +
        `Puedes cancelar y borrarlas o ajustarlas primero.\n\n${pregunta}`;
    }
    if (!confirm(pregunta)) e.preventDefault();
  });
}

dibujar();
