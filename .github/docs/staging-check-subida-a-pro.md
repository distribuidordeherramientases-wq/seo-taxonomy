# STAGING → WordPress Plugin Check → PRO

Workflow: `.github/workflows/staging_check_subida_a_pro.yml`

## Operación

**Automático:** cada merge que cambie plugins o el sistema de despliegue en la rama `staging` activa la cadena. No hay que iniciar cada workflow por separado. El workflow antiguo `deploy-staging.yml` ya no se ejecuta solo en `push` para evitar escrituras FTPS duplicadas.

**Manual (reintento/recuperación):** en **Actions**, selecciona **STAGING -> Plugin Check -> PRO (gated)**, pulsa **Run workflow** y elige **staging**.

- Por defecto `full_sync=false`: la fase de STAGING/Focazul sube solo los archivos modificados según `deploy_staging_delta.py`. `full_sync=true` fuerza reconciliación completa, solo cuando sea necesaria.
- La fase 1 reutiliza `deploy-staging.yml` y exige éxito de STAGING **y Focazul** (ambos si hay cambios).
- La fase 2 reutiliza `taxocommerce-plugin-check.yml`. Selecciona **toda la familia** de plugins instalables `taxocommerce-*`, verifica ZIPs limpios y ejecuta el plugin oficial WordPress Plugin Check en modo estricto: errores **o advertencias** abortan.
- La fase 3 reutiliza `despliege_de_main_a_pro.yml` (nombre histórico real del archivo), comprueba que el commit de `staging` no haya cambiado desde el chequeo y solo entonces publica los plugins **ya instalados en PRO**. Mantiene la validación de hashes SHA-256 del despliegue.
- Los tres workflows antiguos siguen disponibles de forma independiente para diagnóstico, pero el recorrido ordinario se inicia automáticamente con el nuevo workflow.
- El despliegue STAGING/Focazul reintenta cada transferencia fallida un máximo de **tres veces**, con esperas progresivas de 20 y 40 segundos. El chequeo de código no se repite sin cambios, porque hacerlo no corrige fallos deterministas.
- Si un nuevo commit desplaza la rama `staging` mientras espera otro lanzamiento, el lanzamiento obsoleto se omite. Antes de PRO se comprueba de nuevo el SHA exacto revisado.

## En caso de fallo

La siguiente fase **no se inicia**. El último job crea un issue enlazado a la ejecución, el commit y los resultados de cada fase.

**Reparación asistida opcional**: si existe el secreto GitHub Actions `COPILOT_AGENT_TOKEN` con un token de usuario autorizado para el agente Copilot de GitHub, el issue se asigna automáticamente a `copilot-swe-agent[bot]` con instrucciones para investigar y abrir un PR dirigido a `staging`. Sin ese secreto, se crea el issue, pero no puede generarse una corrección automática de código. El agente de GitHub y su licencia deben estar habilitados.

Tras corregir el código y **fusionar el PR de reparación**, se produce otro `push` a `staging` y se repiten los tres pasos. Los fallos sin cambios no generan un bucle infinito: solo se reintenta un número limitado de veces la transferencia FTPS. Los PR generados por un agente deben superar las comprobaciones habituales; no se habilita auto-merge de código sin revisar.

Un workflow YAML no puede inferir ni reparar de forma segura errores arbitrarios de PHP, WordPress, configuración FTPS o conectividad. Para corregirlos: inspeccionar logs, preparar un cambio, abrir PR a `staging`, verificar y hacer merge; la ejecución de **las tres fases** se activa de nuevo automáticamente al fusionar. No hay auto-merge de código desconocido ni atajos que desactiven Plugin Check.

## Integridad

- El lanzamiento requiere `refs/heads/staging`.
- La publicación de PRO está bloqueada por `needs.staging.result == 'success'` y `needs.plugin-check.result == 'success'`.
- El commit de los chequeos se compara con el de `staging` antes de publicar.
- Los secretos FTPS/REST se heredan únicamente en los workflows reutilizados.
- No usar `workflow_dispatch` directamente sobre `despliege_de_main_a_pro.yml` si se requiere la garantía del circuito completo, porque ese workflow histórico sigue pudiendo lanzarse de forma independiente.
