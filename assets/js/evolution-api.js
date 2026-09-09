/**
 * Cliente JavaScript para Evolution API
 * Módulo reutilizable para enviar mensajes de WhatsApp desde el frontend
 */

class EvolutionAPIClient {
    constructor(config = {}) {
        this.apiBaseUrl = config.apiBaseUrl || 'api/whatsapp/';
        this.getAuthToken = config.getAuthToken || this.defaultGetAuthToken;
    }
    
    /**
     * Obtiene el token de autenticación por defecto
     */
    defaultGetAuthToken() {
        // Intentar obtener desde cookie
        const cookies = document.cookie.split(';');
        for (let cookie of cookies) {
            const [name, value] = cookie.trim().split('=');
            if (name === 'session_token') {
                return value;
            }
        }
        return null;
    }
    
    /**
     * Realiza una petición autenticada
     */
    async fetchWithAuth(url, options = {}) {
        const token = await this.getAuthToken();
        
        const headers = {
            'Content-Type': 'application/json',
            ...options.headers
        };
        
        if (token) {
            headers['Authorization'] = `Bearer ${token}`;
        }
        
        return fetch(url, {
            ...options,
            headers,
            credentials: 'include'
        });
    }
    
    /**
     * Envía un mensaje de texto por WhatsApp
     * @param {string} telefono - Número de teléfono del destinatario
     * @param {string} mensaje - Mensaje a enviar
     * @returns {Promise<Object>} Respuesta de la API
     */
    async sendTextMessage(telefono, mensaje) {
        try {
            const response = await this.fetchWithAuth(`${this.apiBaseUrl}send-message.php`, {
                method: 'POST',
                body: JSON.stringify({
                    telefono: telefono,
                    mensaje: mensaje
                })
            });
            
            if (!response.ok) {
                const errorResult = await response.json().catch(() => ({}));
                throw new Error(errorResult.error || `Error HTTP: ${response.status}`);
            }
            
            const result = await response.json();
            
            if (!result.success) {
                throw new Error(result.error || 'Error al enviar mensaje');
            }
            
            return result;
        } catch (error) {
            console.error('Error enviando mensaje WhatsApp:', error);
            throw error;
        }
    }
}

// Instancia global para uso en el proyecto
const evolutionAPI = new EvolutionAPIClient();


