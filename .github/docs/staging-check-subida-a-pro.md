# STAGING → WordPress Plugin Check → PRO

Workflow: `.github/workflows/staging_check_subida_a_pro.yml`

## Operación

En **Actions**, selecciona **STAGING -> Plugin Check -> PRO (gated)** y pulsa **Run workflow**, seleccionando **staging**.

- Por defecto `full_sync=false`: la fase de STAGING/Focazul sube solo los archivos modificados según `deploy_staging_delta.py`. `full_sync=true` fuerza reconciliación completa, solo cuando sea necesaria.
- La fase 1 reutiliza `deploy-staging.yml` y exige éxito de STAGING **y Focazul** (ambos si hay cambios).
- La fase 2 reutiliza `taxocommerce-plugin-check.yml`. Selecciona **toda la familia** de plugins instalables `taxocommerce-*`, verifica ZIPs limpios y ejecuta el plugin oficial WordPress Plugin Check en modo estricto: errores **o advertencias** abortan.
- La fase 3 reutiliza `despliege_de_main_a_pro.yml` (nombre histórico real del archivo), comprueba que el commit de `staging` no haya cambiado desde el chequeo y solo entonces publica los plugins **ya instalados en PRO**. Mantiene la validación de hashes SHA-256 del despliegue.
- No se ejecuta automáticamente con cada merge porque `deploy-staging.yml` ya se ejecuta en cada `push` a `staging`. Duplicar la ejecución podría provocar dos despliegues FTPS simultáneos. Los tres workflows siguen disponibles de forma independiente.

## En caso de fallo

La siguiente fase **no se inicia**. El último job crea un issue enlazado a la ejecución, el commit y los resultados de cada fase.

Un workflow YAML no puede inferir ni reparar de forma segura errores arbitrarios de PHP, WordPress, configuración FTPS o conectividad. Para corregirlos: inspeccionar logs, preparar un cambio, abrir PR a `staging`, verificar, hacer merge, y ejecutar de nuevo **las tres fases** desde el comienzo. No hay auto-merge de código desconocido ni atajos que desactiven Plugin Check.

## Integridad

- El lanzamiento requiere `refs/heads/staging`.
- La publicación de PRO está bloqueada por `needs.staging.result == 'success'` y `needs.plugin-check.result == 'success'`.
- El commit de los chequeos se compara con el de `staging` antes de publicar.
- Los secretos FTPS/REST se heredan únicamente en los workflows reutilizados.
- No usar `workflow_dispatch` directamente sobre `despliege_de_main_a_pro.yml` si se requiere la garantía del circuito completo, porque ese workflow histórico sigue pudiendo lanzarse de forma independiente.
