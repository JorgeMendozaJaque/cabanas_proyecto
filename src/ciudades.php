<?php
// Regiones de Chile (de norte a sur) con sus ciudades principales. Arma el desplegable y valida en el servidor.
// Para agregar una ciudad basta con sumarla aquí; los nombres no deben repetirse entre regiones.
const CIUDADES = [
    'Arica y Parinacota' => ['Arica', 'Putre'],
    'Tarapacá' => ['Iquique', 'Alto Hospicio', 'Pozo Almonte'],
    'Antofagasta' => ['Antofagasta', 'Calama', 'Tocopilla', 'Mejillones', 'Taltal', 'San Pedro de Atacama'],
    'Atacama' => ['Copiapó', 'Vallenar', 'Caldera', 'Chañaral', 'Diego de Almagro'],
    'Coquimbo' => ['La Serena', 'Coquimbo', 'Ovalle', 'Illapel', 'Vicuña', 'Los Vilos', 'Salamanca'],
    'Valparaíso' => ['Valparaíso', 'Viña del Mar', 'Quilpué', 'Villa Alemana', 'Concón', 'San Antonio', 'Quillota', 'La Calera', 'Limache', 'Los Andes', 'San Felipe', 'Casablanca', 'La Ligua'],
    'Metropolitana de Santiago' => ['Santiago', 'Puente Alto', 'Maipú', 'La Florida', 'Las Condes', 'Providencia', 'Ñuñoa', 'Peñalolén', 'La Reina', 'Vitacura', 'Lo Barnechea', 'Pudahuel', 'Quilicura', 'San Bernardo', 'Colina', 'Buin', 'Talagante', 'Peñaflor', 'Melipilla'],
    "Libertador General Bernardo O'Higgins" => ['Rancagua', 'Machalí', 'San Fernando', 'Rengo', 'San Vicente', 'Santa Cruz', 'Pichilemu'],
    'Maule' => ['Talca', 'Curicó', 'Linares', 'Constitución', 'Cauquenes', 'Parral', 'Molina', 'San Javier'],
    'Ñuble' => ['Chillán', 'Chillán Viejo', 'San Carlos', 'Bulnes', 'Yungay', 'Quirihue'],
    'Biobío' => ['Concepción', 'Talcahuano', 'San Pedro de la Paz', 'Hualpén', 'Chiguayante', 'Coronel', 'Lota', 'Penco', 'Tomé', 'Los Ángeles', 'Mulchén', 'Nacimiento', 'Cañete', 'Arauco', 'Curanilahue'],
    'La Araucanía' => ['Temuco', 'Padre Las Casas', 'Villarrica', 'Pucón', 'Angol', 'Victoria', 'Lautaro', 'Nueva Imperial', 'Pitrufquén', 'Collipulli', 'Curacautín', 'Traiguén', 'Carahue'],
    'Los Ríos' => ['Valdivia', 'La Unión', 'Río Bueno', 'Panguipulli', 'Los Lagos', 'Paillaco', 'Futrono', 'Lanco', 'Mariquina'],
    'Los Lagos' => ['Puerto Montt', 'Puerto Varas', 'Osorno', 'Frutillar', 'Llanquihue', 'Calbuco', 'Purranque', 'Castro', 'Ancud', 'Quellón', 'Chaitén'],
    'Aysén' => ['Coyhaique', 'Puerto Aysén', 'Chile Chico', 'Cochrane'],
    'Magallanes' => ['Punta Arenas', 'Puerto Natales', 'Porvenir', 'Puerto Williams'],
];

// Para el botón "Soy extranjero": países reconocidos por la ONU, sin Chile. Se guardan en la misma columna que la ciudad.
const PAISES = [
    'Afganistán', 'Albania', 'Alemania', 'Andorra', 'Angola', 'Antigua y Barbuda', 'Arabia Saudita', 'Argelia', 'Argentina', 'Armenia',
    'Australia', 'Austria', 'Azerbaiyán', 'Bahamas', 'Bangladés', 'Barbados', 'Baréin', 'Bélgica', 'Belice', 'Benín', 'Bielorrusia',
    'Birmania', 'Bolivia', 'Bosnia y Herzegovina', 'Botsuana', 'Brasil', 'Brunéi', 'Bulgaria', 'Burkina Faso', 'Burundi', 'Bután',
    'Cabo Verde', 'Camboya', 'Camerún', 'Canadá', 'Catar', 'Chad', 'China', 'Chipre', 'Colombia', 'Comoras', 'Corea del Norte',
    'Corea del Sur', 'Costa de Marfil', 'Costa Rica', 'Croacia', 'Cuba', 'Dinamarca', 'Dominica', 'Ecuador', 'Egipto', 'El Salvador',
    'Emiratos Árabes Unidos', 'Eritrea', 'Eslovaquia', 'Eslovenia', 'España', 'Estados Unidos', 'Estonia', 'Esuatini', 'Etiopía',
    'Filipinas', 'Finlandia', 'Fiyi', 'Francia', 'Gabón', 'Gambia', 'Georgia', 'Ghana', 'Granada', 'Grecia', 'Guatemala', 'Guinea',
    'Guinea Ecuatorial', 'Guinea-Bisáu', 'Guyana', 'Haití', 'Honduras', 'Hungría', 'India', 'Indonesia', 'Irak', 'Irán', 'Irlanda',
    'Islandia', 'Islas Marshall', 'Islas Salomón', 'Israel', 'Italia', 'Jamaica', 'Japón', 'Jordania', 'Kazajistán', 'Kenia',
    'Kirguistán', 'Kiribati', 'Kuwait', 'Laos', 'Lesoto', 'Letonia', 'Líbano', 'Liberia', 'Libia', 'Liechtenstein', 'Lituania',
    'Luxemburgo', 'Macedonia del Norte', 'Madagascar', 'Malasia', 'Malaui', 'Maldivas', 'Malí', 'Malta', 'Marruecos', 'Mauricio',
    'Mauritania', 'México', 'Micronesia', 'Moldavia', 'Mónaco', 'Mongolia', 'Montenegro', 'Mozambique', 'Namibia', 'Nauru', 'Nepal',
    'Nicaragua', 'Níger', 'Nigeria', 'Noruega', 'Nueva Zelanda', 'Omán', 'Países Bajos', 'Pakistán', 'Palaos', 'Palestina', 'Panamá',
    'Papúa Nueva Guinea', 'Paraguay', 'Perú', 'Polonia', 'Portugal', 'Reino Unido', 'República Centroafricana', 'República Checa',
    'República del Congo', 'República Democrática del Congo', 'República Dominicana', 'Ruanda', 'Rumania', 'Rusia', 'Samoa',
    'San Cristóbal y Nieves', 'San Marino', 'San Vicente y las Granadinas', 'Santa Lucía', 'Santo Tomé y Príncipe', 'Senegal', 'Serbia',
    'Seychelles', 'Sierra Leona', 'Singapur', 'Siria', 'Somalia', 'Sri Lanka', 'Sudáfrica', 'Sudán', 'Sudán del Sur', 'Suecia', 'Suiza',
    'Surinam', 'Tailandia', 'Tanzania', 'Tayikistán', 'Timor Oriental', 'Togo', 'Tonga', 'Trinidad y Tobago', 'Túnez', 'Turkmenistán',
    'Turquía', 'Tuvalu', 'Ucrania', 'Uganda', 'Uruguay', 'Uzbekistán', 'Vanuatu', 'Vaticano', 'Venezuela', 'Vietnam', 'Yemen', 'Yibuti',
    'Zambia', 'Zimbabue',
];

// Región de una ciudad de la lista, "Extranjero" si es un país, o '' si no está en ninguna lista.
function region_de(string $ciudad): string
{
    foreach (CIUDADES as $region => $ciudades) if (in_array($ciudad, $ciudades, true)) return $region;
    return in_array($ciudad, PAISES, true) ? 'Extranjero' : '';
}

function ciudad_valida(string $ciudad): bool
{
    return in_array($ciudad, array_merge(PAISES, ...array_values(CIUDADES)), true);
}
