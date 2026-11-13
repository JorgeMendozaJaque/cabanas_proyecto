# Cabañas Proyecto

Plantilla de proyecto con el modo **Ponytail** ya configurado: el agente de IA escribe solo el código que la tarea necesita, reutiliza lo que ya existe y deja un test pequeño en la lógica importante.

## Cómo usarlo

1. Copia esta carpeta (o renómbrala con el nombre de tu proyecto).
2. Ábrela en tu editor:
   - **Antigravity**: lee `AGENTS.md` como reglas del workspace y las skills desde `.agents/skills/`.
   - **Claude Code**: lee `CLAUDE.md` (que importa `AGENTS.md`) y las skills desde `.claude/skills/`.
   - **Cursor, Codex, Copilot, etc.**: leen `AGENTS.md` en la raíz.
3. Pon tu código en `src/` y pide tareas al agente como siempre.

## Comandos / skills incluidas

| Skill | Para qué sirve |
|---|---|
| `/ponytail [lite\|full\|ultra\|off]` | Activa o cambia el nivel del modo |
| `/ponytail-review` | Revisa los cambios (diff) y el código que tocan |
| `/ponytail-audit` | Busca y ordena problemas en todo el repo |
| `/ponytail-debt` | Junta los comentarios `shortcut:` en una lista de deuda técnica |
| `/ponytail-gain` | Muestra cuánto código se ahorró |
| `/ponytail-help` | Ayuda rápida |

## Qué NO incluye (a propósito)

No se copiaron los *hooks* ni scripts de Ponytail (código que se ejecuta solo). Por eso el nivel elegido no se guarda entre sesiones y no hay barra de métricas. Si los quieres, instala el plugin oficial:

- Antigravity CLI: `agy plugin install https://github.com/DietrichGebert/ponytail`
- Más opciones: https://github.com/dietrichgebert/ponytail/blob/main/INSTALL.md

Ponytail es de Dietrich Gebert, licencia MIT (ver `LICENSE-ponytail`).
