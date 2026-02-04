Documentación Completa del Sistema de Gestión Vial 

Este documento contiene toda la documentación técnica y funcional necesaria para que un nuevo informático pueda continuar el desarrollo, soporte o mantenimiento del sistema interno de Gestión Vial. 

Contenido 

1. Descripción General 

2. Arquitectura del Sistema 

3. Tecnologías Utilizadas 

4. Estructura de Directorios 

5. Base de Datos (Tablas y Relaciones) 

6. Sistema de Roles y Permisos 

7. Módulos del Sistema 

8. Flujo de Crónicas 

9. Logs y Auditoría 

10. Requisitos del Servidor 

11. Procedimiento de Instalación 

12. Errores Comunes y Soluciones 

13. Mejoras Recomendadas 

1. Descripción General 

El Sistema de Gestión Vial es una aplicación web utilizada internamente por la municipalidad para gestionar proyectos, crónicas de campo, inventarios, terrenos, encargados, inspectores y registros históricos. 

2. Arquitectura del Sistema 

La aplicación está construida con una arquitectura modular basada en PHP y MySQL. Cada módulo opera de forma independiente pero se interconecta mediante relaciones SQL y un sistema centralizado de autenticación y permisos. 

3. Tecnologías Utilizadas 

- PHP 8 

- MariaDB/MySQL 

- Apache 2.4 

- Bootstrap 5.3 

- Select2 para listas desplegables 

- CKEditor 5 para edición de texto enriquecido 

- jQuery 

4. Estructura de Directorios 

/gestion_vial_ui/ 
│   index.php 
│   login.php 
│   logout.php 
│ 
├── auth.php 
├── config/db.php 
├── includes/ 
│   ├── header.php 
│   └── footer.php 
│ 
├── pages/ 
│   ├── proyectos/ 
│   ├── cronicas/ 
│   ├── historico/ 
│   ├── caminos/ 
│   ├── modalidades/ 
│   ├── encargados/ 
│   ├── inspectores/ 
│   ├── papelera/ 
│   └── logs/ 
│ 
└── data/proyectos/<nombre>/ 
       ├── cronicas_img/ 
       ├── cronicas_docs/ 
       └── cronicas_firmadas/ 

5. Base de Datos 

Tabla: usuarios 

   - id 

   - usuario 

   - clave 

   - nombre 

   - rol 

Tabla: proyectos 

   - id 

   - nombre 

   - encargado_id 

   - modalidad_id 

   - inventario_id 

   - estado 

   - activo 

Tabla: cronicas 

   - id 

   - consecutivo 

   - proyecto_id 

   - usuario_id 

   - tipo 

   - comentarios 

   - observaciones 

   - imagenes 

   - documentos 

   - firmados 

   - estado_registro 

   - fecha 

Tabla: modalidades 

   - id 

   - nombre 

Tabla: encargados 

   - id 

   - nombre 

   - activo 

Tabla: logs_acciones 

   - id 

   - usuario 

   - rol 

   - accion 

   - detalle 

   - ip 

   - fecha 

6. Sistema de Roles y Permisos 

- admin: acceso total, exceptuando edición de crónicas. 

- ingeniero: acceso a proyectos, inventario, logs. 

- inspector: único que puede crear crónicas. 

- vista: solo lectura. 

7. Módulos del Sistema 

- Proyectos: Crea, edita y administra proyectos viales. 

- Crónicas: Reportes de campo. Solo inspector puede crear. 

- Histórico: Control de versiones de proyectos. 

- Inventario/Caminos: Gestión de vías y terrenos. 

- Encargados: Lista de encargados activos. 

- Inspectores: Gestión de usuarios inspectores. 

- Modalidades: Tipos de proyecto. 

- Logs: Auditoría completa del sistema. 

- Papelera: Gestión de elementos eliminados. 

8. Flujo de Crónicas 

1. Inspector selecciona proyecto. 
2. Se cargan automáticamente encargado, distrito y estado. 
3. Se eligen tipos de crónica. 
4. Se agregan comentarios y observaciones. 
5. Se suben archivos. 
6. El sistema genera consecutivo GV-XXX-AAAA. 
7. Se almacenan archivos en /data/proyectos/<nombre>/. 
8. Se registra acción en logs. 
 

9. Logs y Auditoría 

Cada acción del sistema genera un registro JSON estructurado en logs_acciones. Incluye ID del usuario, rol, acción, IP, detalle y fecha. 

10. Requisitos del Servidor 

- Ubuntu 20.04 o superior 
- Apache 2.4 
- PHP 8.1+ 
- MariaDB 10.3+ 
- Extensiones PHP: mysqli, json, mbstring 

11. Procedimiento de Instalación 

1. Copiar el sistema a /var/www/html/gestion_vial_ui/ 
2. Configurar Apache y permisos. 
3. Importar base de datos. 
4. Editar config/db.php. 
5. Asegurar permisos de /data/. 
6. Probar acceso vía navegador. 

12. Errores Comunes y Soluciones 

- Cannot modify header: mover header() antes de cargar header.php. 
- Archivos no suben: revisar permisos chmod 777. 
- Menús vacíos: revisar roles y auth.php. 

13. Mejoras Recomendadas 

- Crear API REST. 
- Migrar a Laravel. 
- Generar reportes PDF. 
- Agregar Dashboard. 


CONTINUIDAD 
ENLAZAR  EL MUDULO DE IMPRIMIR A DIRECTORIO DE ACTIVOS PARA QUE JALE LA INFORMACION NECESARIA  

IMPORTANTE 

REUNION CON LOS INGENIEROS DE GESTION PARA AFINAR EL SEGUNDO NIVEL...¡¡¡


Imágenes de Referencia 

Captura: d97be9b0-3873-466d-a8af-a87beec8c06c.png 

 

Captura: 8b3bdaf6-2c94-4fff-b069-4d8c591ce140.png 

 

Captura: 5241f21f-b2ec-4346-aa75-ede112257de2.png 

 

Captura: e6a8a263-4c28-401d-bab1-a359a0b3f6a8.png 

 