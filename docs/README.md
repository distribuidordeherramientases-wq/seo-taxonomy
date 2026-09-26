# Documentación de SEO Taxonomy

Esta carpeta contiene la documentación funcional y el manual de usuario del plugin.

La documentación se divide en dos niveles:

1. **Introducción y visión general**: explica qué es SEO Taxonomy, su arquitectura y los servicios principales.
2. **Manual de usuario**: explica cómo utilizar el plugin desde WordPress, incluyendo pestañas, tablas, filtros, botones, casillas, estados, procesos y consecuencias de cada acción.

## Documentos

- [Manual de usuario](MANUAL-USUARIO.md)
- [Marketing y campañas](MARKETING.md)
- [Versiones y publicación](RELEASE-PROCESS.md)
- [Conexiones y credenciales](CONEXIONES-CREDENCIALES.md)

## Criterio del manual

El manual documenta la **interfaz visible para el usuario**. No pretende explicar clases PHP, funciones internas ni la estructura de archivos salvo que resulte necesario para entender una operación.

Cada control debe indicar, cuando corresponda:

- qué hace;
- qué datos utiliza;
- qué modifica;
- si lanza un proceso;
- si consume una API;
- si puede eliminar o reemplazar información;
- qué resultado debe esperar el usuario;
- qué hacer ante un error.

Los campos abiertos incluyen ejemplos de formato. Las credenciales, tokens, contraseñas y secretos que aparecen en la documentación son **valores ficticios y no deben utilizarse en producción**.

> Este manual debe mantenerse alineado con la interfaz de la rama activa del plugin. Si una pantalla cambia, la documentación correspondiente debe actualizarse en la misma entrega.
## Sincronización con GitHub Wiki

Los documentos configurados en el workflow de documentación se sincronizan automáticamente con la Wiki mediante GitHub Actions al actualizar la rama `staging`. Actualmente se publican el manual de usuario, Marketing, conexiones/credenciales y el procedimiento de versiones/publicación.

