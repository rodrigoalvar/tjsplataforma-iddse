# Script para configurar GhostScript para que Imagick lo encuentre
# Ejecutar como Administrador

$gsBinPath = "C:\Program Files\gs\gs10.05.1\bin"
$gsExe = Join-Path $gsBinPath "gswin64c.exe"

Write-Host "========================================"
Write-Host "Configuración de GhostScript para Imagick"
Write-Host "========================================"
Write-Host ""

# Verificar que GhostScript existe
if (-not (Test-Path $gsExe)) {
    Write-Host "❌ Error: No se encontró gswin64c.exe en: $gsBinPath" -ForegroundColor Red
    Write-Host "Por favor, verifica la ubicación de GhostScript" -ForegroundColor Yellow
    exit 1
}

Write-Host "✅ GhostScript encontrado en: $gsExe" -ForegroundColor Green
Write-Host ""

# Opción 1: Crear alias gs.exe en el mismo directorio (requiere admin)
Write-Host "Opción 1: Crear alias gs.exe..." -ForegroundColor Cyan

$gsAlias = Join-Path $gsBinPath "gs.exe"
if (Test-Path $gsAlias) {
    Write-Host "✅ gs.exe ya existe en: $gsAlias" -ForegroundColor Green
} else {
    try {
        # Intentar crear enlace simbólico (requiere admin)
        $currentPath = Get-Location
        Set-Location $gsBinPath
        cmd /c mklink gs.exe gswin64c.exe 2>&1 | Out-Null
        if ($LASTEXITCODE -eq 0) {
            Write-Host "✅ Enlace simbólico gs.exe creado exitosamente" -ForegroundColor Green
        } else {
            Write-Host "⚠️ No se pudo crear enlace simbólico (requiere permisos de admin)" -ForegroundColor Yellow
            Write-Host "   Intentando copiar archivo..." -ForegroundColor Yellow
            Copy-Item $gsExe $gsAlias -ErrorAction SilentlyContinue
            if (Test-Path $gsAlias) {
                Write-Host "✅ Copia gs.exe creada exitosamente" -ForegroundColor Green
            } else {
                Write-Host "❌ No se pudo crear gs.exe (requiere permisos de admin)" -ForegroundColor Red
            }
        }
        Set-Location $currentPath
    } catch {
        Write-Host "❌ Error al crear alias: $_" -ForegroundColor Red
    }
}
Write-Host ""

# Opción 2: Agregar al PATH del sistema (requiere admin)
Write-Host "Opción 2: Agregar al PATH del sistema..." -ForegroundColor Cyan

$currentPath = [Environment]::GetEnvironmentVariable("Path", "Machine")
if ($currentPath -like "*$gsBinPath*") {
    Write-Host "✅ El directorio ya está en PATH del sistema" -ForegroundColor Green
} else {
    Write-Host "⚠️ El directorio NO está en PATH del sistema" -ForegroundColor Yellow
    Write-Host "   Para agregarlo manualmente:" -ForegroundColor Yellow
    Write-Host "   1. Abrir Configuración → Variables de entorno" -ForegroundColor Yellow
    Write-Host "   2. En Variables del sistema, seleccionar 'Path'" -ForegroundColor Yellow
    Write-Host "   3. Click 'Editar' → 'Nuevo'" -ForegroundColor Yellow
    Write-Host "   4. Agregar: $gsBinPath" -ForegroundColor Yellow
    Write-Host ""
    Write-Host "   O ejecutar este comando como Administrador:" -ForegroundColor Yellow
    Write-Host "   [Environment]::SetEnvironmentVariable('Path', `"$currentPath;$gsBinPath`", 'Machine')" -ForegroundColor Cyan
}
Write-Host ""

# Opción 3: Agregar al PATH del usuario actual
Write-Host "Opción 3: Agregar al PATH del usuario actual..." -ForegroundColor Cyan

$userPath = [Environment]::GetEnvironmentVariable("Path", "User")
if ($userPath -like "*$gsBinPath*") {
    Write-Host "✅ El directorio ya está en PATH del usuario" -ForegroundColor Green
} else {
    Write-Host "Agregando al PATH del usuario..." -ForegroundColor Yellow
    try {
        $newUserPath = if ($userPath) { "$userPath;$gsBinPath" } else { $gsBinPath }
        [Environment]::SetEnvironmentVariable("Path", $newUserPath, "User")
        Write-Host "✅ Agregado al PATH del usuario exitosamente" -ForegroundColor Green
        Write-Host "   NOTA: Puedes necesitar reiniciar terminal/IDE para que tome efecto" -ForegroundColor Yellow
    } catch {
        Write-Host "⚠️ No se pudo agregar al PATH del usuario: $_" -ForegroundColor Yellow
    }
}
Write-Host ""

# Verificar si gs funciona ahora
Write-Host "Verificando si 'gs' funciona..." -ForegroundColor Cyan
$env:Path += ";$gsBinPath"
$gsCheck = Get-Command gs -ErrorAction SilentlyContinue
if ($gsCheck) {
    Write-Host "✅ 'gs' funciona correctamente" -ForegroundColor Green
    & gs --version 2>&1 | Write-Host
} else {
    Write-Host "⚠️ 'gs' aún no funciona. Intenta:" -ForegroundColor Yellow
    Write-Host "   1. Reiniciar terminal/IDE" -ForegroundColor Yellow
    Write-Host "   2. Verificar que el directorio está en PATH" -ForegroundColor Yellow
    Write-Host "   3. Verificar que gs.exe existe en: $gsBinPath" -ForegroundColor Yellow
}
Write-Host ""

# Mostrar resumen
Write-Host "========================================"
Write-Host "Resumen" -ForegroundColor Cyan
Write-Host "========================================"
Write-Host "Ubicación GhostScript: $gsExe" -ForegroundColor White
Write-Host "Directorio bin: $gsBinPath" -ForegroundColor White
Write-Host ""

if (Test-Path $gsAlias) {
    Write-Host "✅ Alias gs.exe: EXISTE" -ForegroundColor Green
} else {
    Write-Host "❌ Alias gs.exe: NO EXISTE (requiere permisos de admin)" -ForegroundColor Red
}

$machinePath = [Environment]::GetEnvironmentVariable("Path", "Machine")
if ($machinePath -like "*$gsBinPath*") {
    Write-Host "✅ En PATH del sistema: SÍ" -ForegroundColor Green
} else {
    Write-Host "⚠️ En PATH del sistema: NO" -ForegroundColor Yellow
}

$userPath = [Environment]::GetEnvironmentVariable("Path", "User")
if ($userPath -like "*$gsBinPath*") {
    Write-Host "✅ En PATH del usuario: SÍ" -ForegroundColor Green
} else {
    Write-Host "⚠️ En PATH del usuario: NO" -ForegroundColor Yellow
}
Write-Host ""

Write-Host "Próximos pasos:" -ForegroundColor Cyan
Write-Host "1. Si creaste alias o agregaste PATH, REINICIAR WAMP completamente" -ForegroundColor Yellow
Write-Host "2. Probar conversión PDF a PNG en Informes Manager" -ForegroundColor Yellow
Write-Host "3. Verificar logs para confirmar que funciona" -ForegroundColor Yellow
Write-Host ""

