from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]


def patch(path_str, replacements):
    path = ROOT / path_str
    text = path.read_text(encoding='utf-8')
    original = text
    for old, new in replacements:
        if old in text:
            text = text.replace(old, new)
    if text != original:
        path.write_text(text, encoding='utf-8')


def add_auth_require(path_str):
    path = ROOT / path_str
    text = path.read_text(encoding='utf-8')
    auth = "require_once rtrim((string) ($_SERVER['DOCUMENT_ROOT'] ?? ''), '/') . '/api/solicitud-venta/autorizacion.php';"
    if auth in text:
        return
    old = "require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';"
    old_root = "require_once dirname(__DIR__) . '/includes/bootstrap.php';"
    if old in text:
        text = text.replace(old, auth, 1)
    elif old_root in text:
        text = text.replace(old_root, auth, 1)
    else:
        marker = "declare(strict_types=1);"
        if marker not in text:
            raise RuntimeError(f'{path_str}: no se encontro punto para cargar autorizacion')
        text = text.replace(marker, marker + '\n\n' + auth, 1)
    path.write_text(text, encoding='utf-8')


UI_FILES = [
    'index.php',
    'inicio/index.php',
    'vobo/index.php',
]

API_AUTH_FILES = [
    'api/solicitud-venta/vobo.php',
    'api/solicitud-venta/vobo-firma.php',
    'api/solicitud-venta/expediente-final-prueba.php',
    'api/solicitud-venta/grupo-notificacion-prueba.php',
    'api/solicitud-venta/notificacion-prueba.php',
]

for file in UI_FILES + API_AUTH_FILES:
    add_auth_require(file)

COMMON_REPLACEMENTS = [
    ('portal_require_authentication()', 'svSolicitudRequireAuthentication()'),
    ('portal_is_authenticated()', 'svSolicitudEstaAutenticado()'),
    ('portal_user()', 'svSolicitudUsuario()'),
    ('portal_vobo_role()', 'svSolicitudVoboRole()'),
    ('portal_user_can_vobo()', 'svSolicitudCanVobo()'),
    ('portal_user_can_cobranza_vobo()', 'svSolicitudCanCobranzaVobo()'),
    ('portal_user_can_any_vobo()', 'svSolicitudCanAnyVobo()'),
    ('portal_require_vobo()', 'svSolicitudRequireVobo()'),
    ('portal_require_cobranza_vobo()', 'svSolicitudRequireCobranzaVobo()'),
]

for file in UI_FILES + API_AUTH_FILES:
    patch(file, COMMON_REPLACEMENTS)

# El backend ya esta dentro del repo propio. Evitamos comentarios que sigan
# describiendolo como si viviera dentro del Portal.
patch('api/solicitud-venta/_common.php', [
    ('// Solicitud de Venta vive dentro del Portal y debe reutilizar la misma sesion',
     '// Solicitud de Venta reutiliza la sesion SSO compartida del Portal'),
])

# La fuente de despliegue independiente debe reaccionar a cambios de main.
workflow = ROOT / '.github/workflows/publicar-solicitud-cpanel.yml'
text = workflow.read_text(encoding='utf-8')
if '  push:\n    branches:\n      - main\n' not in text:
    text = text.replace('on:\n  workflow_dispatch:', 'on:\n  push:\n    branches:\n      - main\n  workflow_dispatch:', 1)
text = text.replace(
    '          SOLICITUD_DEPLOY_SCOPE: ${{ inputs.alcance }}',
    "          SOLICITUD_DEPLOY_SCOPE: ${{ github.event_name == 'workflow_dispatch' && inputs.alcance || 'completo' }}"
)
workflow.write_text(text, encoding='utf-8')

# Validaciones de propiedad del repositorio.
required = [
    ROOT / 'api/solicitud-venta/autorizacion.php',
    ROOT / 'api/solicitud-venta/expediente-final-prueba.php',
    ROOT / 'api/solicitud-venta/grupo-notificacion-prueba.php',
    ROOT / 'api/solicitud-venta/notificacion-prueba.php',
    ROOT / 'firma/index.html',
    ROOT / 'tools/deploy_solicitud_ftps.sh',
]
for path in required:
    if not path.is_file() or path.stat().st_size == 0:
        raise RuntimeError(f'Falta archivo autonomo: {path.relative_to(ROOT)}')

for file in UI_FILES + API_AUTH_FILES:
    text = (ROOT / file).read_text(encoding='utf-8')
    if "includes/bootstrap.php" in text:
        raise RuntimeError(f'{file}: aun depende del bootstrap del Portal')
    if 'portal_user_' in text or 'portal_vobo_' in text or 'portal_require_' in text or 'portal_is_authenticated' in text or 'portal_user()' in text:
        raise RuntimeError(f'{file}: aun contiene funciones de negocio del Portal')

print('Solicitud de Venta desacoplada de las funciones de negocio del Portal.')
