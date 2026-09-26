# Documentación de SEO Taxonomy

Esta carpeta contiene la documentación funcional y el manual de usuario del plugin.

La documentación se divide en dos niveles:

1. **Introducción y visión general**: arquitectura, conceptos y servicios.
2. **Manual de usuario**: interfaz real de WordPress, con menús, pestañas, campos, filtros, botones, checkboxes, estados, procesos y consecuencias de cada acción.

## Manual general

- [Manual de usuario](MANUAL-USUARIO.md)
- [Conexiones y credenciales](CONEXIONES-CREDENCIALES.md)
- [Marketing y campañas](MARKETING.md)
- [Versiones y publicación](RELEASE-PROCESS.md)

## Manual por pantalla

- [Inicio](manual/INICIO.md)
- [Productos](manual/PRODUCTOS.md)
- [Categorías](manual/CATEGORIAS.md)
- [Etiquetas y vocabulario](manual/ETIQUETAS-Y-VOCABULARIO.md)
- [Páginas](manual/PAGINAS.md)
- [Entradas](manual/ENTRADAS.md)
- [Imágenes](manual/IMAGENES.md)
- [Informes](manual/INFORMES.md)
- [Dependiente](manual/DEPENDIENTE.md)
- [Herramientas](manual/HERRAMIENTAS.md)
- [Marketing](manual/MARKETING.md)
- [Import / Export](manual/IMPORT-EXPORT.md)
- [Facturas y presupuestos](manual/FACTURAS-Y-PRESUPUESTOS.md)
- [Solucionador](manual/SOLUCIONADOR.md)
- [Ojeador](manual/OJEADOR.md)
- [Comentarista](manual/COMENTARISTA.md)

## Criterio documental

Todo elemento visible debe explicarse. Para cada control se documenta, cuando corresponde:

- qué hace;
- qué valor espera;
- qué datos consulta o modifica;
- si lanza un proceso;
- si consume una API;
- si afecta a producción;
- si elimina o reemplaza información;
- qué resultado debe esperar el usuario.

Los ejemplos de usuarios, claves, tokens, contraseñas y secretos son ficticios.

## Sincronización con GitHub Wiki

La documentación de la rama `staging` se sincroniza automáticamente con la Wiki mediante GitHub Actions. Las páginas conceptuales existentes se conservan y reciben un bloque de **Manual operativo** administrado desde `docs/manual/`.

Una modificación visual del plugin no se considera completamente terminada hasta revisar su documentación.
