/* =====================================================================
   AURORA GPS - FIRMWARE ESP32
   =====================================================================
   Funcionalidades do Sistema:
   - Leitura de posicionamento via módulo GPS NEO-6M (HardwareSerial2).
   - Servidor BLE GATT para transmissão de dados de telemetria e SOS ao App.
   - Envio redundante via HTTP POST para a API REST quando conectado ao Wi-Fi.
   - Interface visual em display OLED SSD1306 (128x64 I2C).
   - Gerenciamento de energia com cálculo percentual de Bateria Li-Ion (ADC).
   - Indicador visual RGB do estado do sistema.
   ===================================================================== */

// --- INCLUSÃO DE BIBLIOTECAS DE REDE E PROTOCOLOS ---
#include <WiFi.h>           // Gerenciamento da pilha de rádio Wi-Fi
#include <HTTPClient.h>     // Cliente para requisições HTTP REST

// --- INCLUSÃO DE BIBLIOTECAS DE SENSORES E PERIFÉRICOS ---
#include <TinyGPS++.h>      // Parser dos quadros NMEA do módulo GPS

// --- INCLUSÃO DE BIBLIOTECAS DE INTERFACE GRÁFICA ---
#include <Wire.h>           // Barramento I2C para comunicação com o display
#include <Adafruit_GFX.h>   // Motor gráfico para renderização visual
#include <Adafruit_SSD1306.h> // Driver do controlador de display OLED

// --- INCLUSÃO DE BIBLIOTECAS BLUETOOTH LOW ENERGY (BLE) ---
#include <BLEDevice.h>      // Gerenciador central de rádio BLE
#include <BLEServer.h>      // Configuração do perfil de Servidor GATT
#include <BLEUtils.h>       // Utilitários de conversão de tipos do BLE
#include <BLE2902.h>        // Implementação do Descritor CCCD (0x2902)

// ---------------------------------------------------------------------
// INICIALIZAÇÃO E PARÂMETROS DO DISPLAY OLED
// ---------------------------------------------------------------------
#define SCREEN_WIDTH 128
#define SCREEN_HEIGHT 64
#define OLED_RESET    -1
Adafruit_SSD1306 display(SCREEN_WIDTH, SCREEN_HEIGHT, &Wire, OLED_RESET);

// ---------------------------------------------------------------------
// MAPAS DE BITS (BITMAPS) DOS ÍCONES DA BARRA DE STATUS (8x8 Pixels)
// ---------------------------------------------------------------------
static const unsigned char PROGMEM icon_wifi[] = {
  0b00000000,
  0b01111110,
  0b10000001,
  0b00111100,
  0b01000010,
  0b00011000,
  0b00000000,
  0b00011000
};

static const unsigned char PROGMEM icon_bt[] = {
  0b00100000,
  0b00101000,
  0b00100100,
  0b10101000,
  0b01110000,
  0b10101000,
  0b00100100,
  0b00101000
};

// ---------------------------------------------------------------------
// CONFIGURAÇÕES E ESTADOS DO BLUETOOTH LOW ENERGY (BLE)
// ---------------------------------------------------------------------
#define SERVICE_UUID        "4fafc201-1fb5-459e-8fcc-c5c9c331914b"
#define CHARACTERISTIC_UUID "beb5483e-36e1-4688-b7f5-ea07361b26a8"

BLEServer* pServer = NULL;
BLECharacteristic* pCharacteristic = NULL;
bool deviceConnected = false;
bool oldDeviceConnected = false;

// Callback para monitorar conexões e desconexões do cliente (Smartphone)
class MyServerCallbacks: public BLEServerCallbacks {
    void onConnect(BLEServer* pServer) override {
      deviceConnected = true;
    }
    void onDisconnect(BLEServer* pServer) override {
      deviceConnected = false;
    }
};

// ---------------------------------------------------------------------
// CONFIGURAÇÕES DE REDE, API E PARÂMETROS DE HARDWARE
// ---------------------------------------------------------------------
const char* WIFI_SSID     = "Aurora";
const char* WIFI_SENHA    = "equipe01aurora";
const char* API_URL       = "http://192.168.1.5/api_aurora_gps/gravar.php";
const char* API_KEY       = "aurora-gps-2026";

const char* DEVICE_NOME   = "Aurora GPS";
const char* DEVICE_MODELO = "ESP32 + NEO-6M";
const char* FIRMWARE      = "1.0.0";

const unsigned long INTERVALO_ENVIO   = 3000;  // Frequência de atualização periódica fixa em 3 segundos (3000 ms)
const unsigned long TEMPO_PRESS_SOS   = 1000;  // Tempo de retenção do botão de SOS (1s)

// MAPEAMENTO DE PINOS DA PLACA (GPIO)
const int pinoBotaoSOS = 4;   // Botão físico de pânico
const int pinoBotaoGPS = 23;  // Botão liga/desliga rastreamento
const int pinR          = 5;   // Anodo/Catodo Vermelho LED RGB
const int pinG          = 18;  // Anodo/Catodo Verde LED RGB
const int pinB          = 19;  // Anodo/Catodo Azul LED RGB
const int pinoBateria   = 34;  // Pino analógico ADC1 para medição de tensão
const int FUSO_HORARIO  = -3;  // Fuso horário local (UTC-3 Brasilia)

// OBJETOS GLOBAIS
TinyGPSPlus gps;
HardwareSerial ss(2); // Usando a Serial2 do ESP32 (RX2=GPIO16, TX2=GPIO17)

// VARIÁVEIS DE CONTROLE DE ESTADO
bool rastreamentoAtivo = false;
bool piscaLEDVerde = false;

// CONTROLE DO BOTÃO SOS
unsigned long tempoInicioSOS = 0;
bool sosDisparado = false;
unsigned long tempoExibicaoSOS = 0;
bool mostrandoTelaSOS = false;

// CONTROLE DE DEBOUNCE DO BOTÃO GPS
int ultimoEstadoBotaoGPS = HIGH;
unsigned long ultimoDebounceGPS = 0;

// CONTROLE DE TEMPORIZADORES DE LOOP
unsigned long ultimoEnvio = 0;
unsigned long ultimaAttDisplay = 0;

String deviceUid;

// ---------------------------------------------------------------------
// FUNÇÃO AUXILIAR: CONTROLE DO LED RGB
// ---------------------------------------------------------------------
void defineRGB(bool r, bool g, bool b) {
  digitalWrite(pinR, r);
  digitalWrite(pinG, g);
  digitalWrite(pinB, b);
}

// ---------------------------------------------------------------------
// FUNÇÃO AUXILIAR: GERAÇÃO DE IDENTIFICADOR ÚNICO DO HARDWARE
// ---------------------------------------------------------------------
String montarDeviceUid() {
  uint64_t mac = ESP.getEfuseMac();
  char buf[24];
  snprintf(buf, sizeof(buf), "ESP32-%04X%08X", (uint16_t)(mac >> 32), (uint32_t)mac);
  return String(buf);
}

// ---------------------------------------------------------------------
// FUNÇÃO AUXILIAR: FORMATAÇÃO DE DATA E HORA COM FUSO HORÁRIO
// ---------------------------------------------------------------------
String montarDataHora() {
  // Evita exibir 21:00 no boot: só aceita hora se o GPS tiver ano atualizado (>2023) e leitura recente
  if (!gps.date.isValid() || !gps.time.isValid() || gps.date.year() < 2024 || gps.time.age() > 2000) {
    return "";
  }

  int ano  = gps.date.year();
  int mes  = gps.date.month();
  int dia  = gps.date.day();
  int hora = gps.time.hour() + FUSO_HORARIO;

  // Ajuste de virada de dia e mês em fuso negativo
  if (hora < 0) { hora += 24; dia--; }
  if (dia < 1)  { mes--; dia = 30; } 
  if (mes < 1)  { mes = 12; ano--; }

  char buf[24];
  snprintf(buf, sizeof(buf), "%04d-%02d-%02d %02d:%02d:%02d",
           ano, mes, dia, hora, gps.time.minute(), gps.time.second());
  return String(buf);
}

// ---------------------------------------------------------------------
// LEITURA DA TENSÃO E CÁLCULO PERCENTUAL DA BATERIA (ADC)
// ---------------------------------------------------------------------
int lerBateriaPercentual() {
  int leituraADC = analogRead(pinoBateria);
  
  // Tensão de entrada considerando divisor de tensão 1:1 com 3.3V máximo na porta
  float tensao = ((float)leituraADC / 4095.0f) * 3.3f * 2.0f; 

  // Mapeia o intervalo operacional da Li-Ion (3.3V = 0% a 4.2V = 100%)
  int percentual = map((int)(tensao * 100), 330, 420, 0, 100);
  return constrain(percentual, 0, 100);
}

// ---------------------------------------------------------------------
// DESENHO GRÁFICO DO ÍCONE DE BATERIA NO OLED
// ---------------------------------------------------------------------
void desenharBateria(int x, int y, int percentual) {
  display.drawRect(x, y, 14, 8, WHITE);           // Corpo da bateria
  display.fillRect(x + 14, y + 2, 2, 4, WHITE);   // Polo positivo
  
  int larguraBarra = map(constrain(percentual, 0, 100), 0, 100, 0, 10);
  if (larguraBarra > 0) {
    display.fillRect(x + 2, y + 2, larguraBarra, 4, WHITE); // Carga interna
  }
}

// ---------------------------------------------------------------------
// RENDERIZAÇÃO DA INTERFACE VISUAL NO DISPLAY OLED
// ---------------------------------------------------------------------
void atualizarOLED() {
  display.clearDisplay();
  display.setTextSize(1);
  display.setTextColor(WHITE);

  // 1. Ícone do Wi-Fi
  if (WiFi.status() == WL_CONNECTED) {
    display.drawBitmap(0, 1, icon_wifi, 8, 8, WHITE);
  } else {
    display.setCursor(0, 1);
    display.print("x");
  }

  // 2. Ícone do Bluetooth
  if (deviceConnected) {
    display.drawBitmap(12, 1, icon_bt, 8, 8, WHITE);
  }

  // 3. HORÁRIO (HH:MM) - CENTRALIZADO (Largura total: 128px, texto: 30px -> X = 49)
  String dataHora = montarDataHora();
  String horaStr = (dataHora != "") ? dataHora.substring(11, 16) : "--:--";
  display.setCursor(49, 1);
  display.print(horaStr);

  // 4. INDICADOR DE BATERIA - ALINHADO IMEDIATAMENTE À ESQUERDA DO ÍCONE (x = 108)
  int pctBateria = lerBateriaPercentual();
  String pctStr = String(pctBateria) + "%";
  int larguraTextoPct = pctStr.length() * 6; // Cada caractere tamanho 1 possui 6px de largura
  int cursorXPct = 105 - larguraTextoPct;    // Posiciona o texto colado 3px antes do ícone (108 - 3 = 105)
  
  display.setCursor(cursorXPct, 1);
  display.print(pctStr);
  
  desenharBateria(108, 1, pctBateria);

  display.drawLine(0, 11, 128, 11, WHITE); // Linha divisória da barra de status

  // Renderização da Tela de Emergência SOS
  if (mostrandoTelaSOS) {
    display.setTextSize(1);
    display.setCursor(55, 25);
    display.println("SOS");
    display.setCursor(40, 37);
    display.println("ENVIADO!");
    display.display();
    return;
  }

  // Renderização do Corpo do Display
  if (!rastreamentoAtivo) {
    display.setCursor(10, 32);
    display.print("RASTREAMENTO OFF");
  } 
  else if (!gps.location.isValid()) {
    display.setCursor(5, 32);
    display.print("Buscando Satelites");
  } 
  else {
    display.setCursor(0, 16);
    display.print("Lat: "); display.println(gps.location.lat(), 6);
    
    display.setCursor(0, 27);
    display.print("Lon: "); display.println(gps.location.lng(), 6);

    if (dataHora != "") {
      String dataStr = dataHora.substring(8,10) + "/" + dataHora.substring(5,7) + "/" + dataHora.substring(0,4);
      display.setCursor(0, 40);
      display.print("Data: "); display.println(dataStr);
      display.setCursor(0, 50);
      display.print("Satelites: "); display.println(gps.satellites.value());
    }
  }
  display.display();
}

// ---------------------------------------------------------------------
// COMUNICAÇÃO HTTP REST (MONTAGEM DO JSON E REQUISIÇÃO POST)
// ---------------------------------------------------------------------
bool enviarParaAPI(bool isSOS = false) {
  if (WiFi.status() != WL_CONNECTED) {
    Serial.println("[API] Erro: Dispositivo desconectado do Wi-Fi!");
    return false;
  }

  String dataHora = montarDataHora();
  String dataEnvio = (dataHora != "" ? dataHora : "2026-01-01 00:00:00");

  // Construção do objeto JSON
  String json = "{";
  json += "\"device_uid\":\"" + deviceUid + "\",";
  json += "\"nome\":\""     + String(DEVICE_NOME)   + "\",";
  json += "\"modelo\":\""   + String(DEVICE_MODELO) + "\",";
  json += "\"firmware\":\"" + String(FIRMWARE)      + "\",";
  json += "\"bateria\":"    + String(lerBateriaPercentual()) + ",";
  json += "\"sos\":"        + String(isSOS ? "true" : "false") + ",";
  
  if (gps.location.isValid()) {
    json += "\"latitude\":"   + String(gps.location.lat(), 6) + ",";
    json += "\"longitude\":"  + String(gps.location.lng(), 6) + ",";
  } else {
    json += "\"latitude\":0.0, \"longitude\":0.0,";
  }
  
  if (gps.altitude.isValid())   json += "\"altitude_m\":"     + String(gps.altitude.meters(), 2) + ",";
  if (gps.speed.isValid())      json += "\"velocidade_kmh\":" + String(gps.speed.kmph(), 2) + ",";
  if (gps.satellites.isValid()) json += "\"satelites\":"      + String(gps.satellites.value()) + ",";
  json += "\"data_hora_gps\":\"" + dataEnvio + "\"}";

  // Envio do Pacote HTTP
  HTTPClient http;
  http.begin(API_URL);
  http.addHeader("Content-Type", "application/json");
  http.addHeader("X-API-Key", API_KEY);

  Serial.println("\n[API] Enviando payload HTTP POST...");
  Serial.println(json);

  int status = http.POST(json);

  if (status > 0) {
    Serial.printf("[API] Status HTTP da resposta: %d\n", status);
    String resposta = http.getString();
    Serial.println("[API] Resposta do Servidor: " + resposta);
  } else {
    Serial.printf("[API] Erro no envio HTTP: %s (Codigo: %d)\n", http.errorToString(status).c_str(), status);
  }

  http.end();
  return status == 200 || status == 201;
}

// ---------------------------------------------------------------------
// ROTINA DE DISPARO DO ALERTA DE EMERGÊNCIA (SOS)
// ---------------------------------------------------------------------
void dispararAlertaSOS() {
  mostrandoTelaSOS = true;
  tempoExibicaoSOS = millis();
  
  defineRGB(HIGH, HIGH, HIGH); // Flash no LED
  enviarParaAPI(true);         // Envio imediato para o servidor web

  // Transmissão de notificação em tempo real via Bluetooth para o aplicativo
  if (deviceConnected) {
    String payload = "ALERT:SOS|Lat:" + String(gps.location.lat(), 6) + "|Lon:" + String(gps.location.lng(), 6);
    pCharacteristic->setValue(payload.c_str());
    pCharacteristic->notify();
  }
  atualizarOLED();
}

// ---------------------------------------------------------------------
// SETUP DE INICIALIZAÇÃO
// ---------------------------------------------------------------------
void setup() {
  Serial.begin(115200);
  ss.begin(9600, SERIAL_8N1, 16, 17); // Inicializa a UART2 do GPS (RX=16, TX=17)

  // Configuração dos Pinos de Entrada com Resistor de Pull-Up Interno
  pinMode(pinoBotaoSOS, INPUT_PULLUP);
  pinMode(pinoBotaoGPS, INPUT_PULLUP);

  pinMode(pinR, OUTPUT);
  pinMode(pinG, OUTPUT);
  pinMode(pinB, OUTPUT);
  pinMode(pinoBateria, INPUT);

  defineRGB(HIGH, HIGH, HIGH); // Ilumina LED no Boot

  deviceUid = montarDeviceUid();

  // Inicialização do Display OLED I2C no endereço 0x3C
  if(!display.begin(SSD1306_SWITCHCAPVCC, 0x3C)) {
    Serial.println("Falha no OLED");
  }
  display.clearDisplay();
  display.setTextSize(2);
  display.setTextColor(WHITE);
  display.setCursor(5, 20);
  display.println("Aurora GPS");
  display.display();

  // Inicialização do Servidor Bluetooth Low Energy
  BLEDevice::init(DEVICE_NOME);
  pServer = BLEDevice::createServer();
  pServer->setCallbacks(new MyServerCallbacks());

  BLEService *pService = pServer->createService(SERVICE_UUID);
  pCharacteristic = pService->createCharacteristic(
                      CHARACTERISTIC_UUID,
                      BLECharacteristic::PROPERTY_READ   |
                      BLECharacteristic::PROPERTY_WRITE  |
                      BLECharacteristic::PROPERTY_NOTIFY
                    );

  pCharacteristic->addDescriptor(new BLE2902()); // Adiciona o descritor de notificação
  pService->start();

  // Configuração dos Pacotes de Anúncio (Advertising)
  BLEAdvertising *pAdvertising = BLEDevice::getAdvertising();
  pAdvertising->addServiceUUID(SERVICE_UUID);
  pAdvertising->setScanResponse(true);
  pAdvertising->setMinPreferred(0x0);
  BLEDevice::startAdvertising();

  // Conexão com a rede Wi-Fi local
  WiFi.mode(WIFI_STA);
  WiFi.begin(WIFI_SSID, WIFI_SENHA);
  
  Serial.print("Conectando ao Wi-Fi");
  int tentativas = 0;
  while (WiFi.status() != WL_CONNECTED && tentativas < 30) {
    delay(500);
    Serial.print(".");
    tentativas++;
  }

  if (WiFi.status() == WL_CONNECTED) {
    Serial.println("\n[Wi-Fi] Conectado com sucesso!");
    Serial.print("[Wi-Fi] IP do ESP32: ");
    Serial.println(WiFi.localIP());
  } else {
    Serial.println("\n[Wi-Fi] Falha ao conectar a rede Wi-Fi.");
  }

  atualizarOLED();
}

// ---------------------------------------------------------------------
// LOOP PRINCIPAL DE EXECUÇÃO
// ---------------------------------------------------------------------
void loop() {
  // Processamento contínuo dos dados seriais NMEA do módulo GPS
  while (ss.available() > 0) {
    gps.encode(ss.read());
  }

  // -------------------------------------------------------------------
  // BLOCO 1: MONITORAMENTO DO BOTÃO SOS (Requer retenção por 1 segundo)
  // -------------------------------------------------------------------
  int leituraSOS = digitalRead(pinoBotaoSOS);

  if (leituraSOS == LOW) {
    if (tempoInicioSOS == 0) {
      tempoInicioSOS = millis();
    }

    if (!sosDisparado && (millis() - tempoInicioSOS >= TEMPO_PRESS_SOS)) {
      sosDisparado = true;
      dispararAlertaSOS();
    }
  } 
  else {
    tempoInicioSOS = 0;
    sosDisparado = false;
  }

  // -------------------------------------------------------------------
  // BLOCO 2: MONITORAMENTO DO BOTÃO GPS (Alternador On/Off com Debounce)
  // -------------------------------------------------------------------
  int leituraGPS = digitalRead(pinoBotaoGPS);

  if (leituraGPS == LOW && ultimoEstadoBotaoGPS == HIGH) {
    if (millis() - ultimoDebounceGPS > 200) { // Filtro de ruído de 200ms
      rastreamentoAtivo = !rastreamentoAtivo;
      atualizarOLED();
      ultimoDebounceGPS = millis();
    }
  }
  ultimoEstadoBotaoGPS = leituraGPS;

  // -------------------------------------------------------------------
  // BLOCO 3: GESTÃO DE TELAS TEMPORÁRIAS E REANÚNCIO BLE
  // -------------------------------------------------------------------
  // Oculta mensagem visual de SOS após 3 segundos
  if (mostrandoTelaSOS && (millis() - tempoExibicaoSOS > 3000)) {
    mostrandoTelaSOS = false;
    atualizarOLED();
  }

  // Reinicia o Advertising quando um celular desconecta
  if (!deviceConnected && oldDeviceConnected) {
    delay(500); 
    pServer->startAdvertising();
    oldDeviceConnected = deviceConnected;
    atualizarOLED();
  }
  if (deviceConnected && !oldDeviceConnected) {
    oldDeviceConnected = deviceConnected;
    atualizarOLED();
  }

  // Atualização periódica do visor OLED (A cada 1 segundo)
  if (millis() - ultimaAttDisplay > 1000) {
    ultimaAttDisplay = millis();
    atualizarOLED();
  }

  // -------------------------------------------------------------------
  // BLOCO 4: TRANSMISSÃO PERIÓDICA DE TELEMETRIA (Fixa em 3 segundos)
  // -------------------------------------------------------------------
  if (rastreamentoAtivo && gps.location.isValid()) {
    if (millis() - ultimoEnvio >= INTERVALO_ENVIO) {
      ultimoEnvio = millis();
      piscaLEDVerde = true;
      
      enviarParaAPI(false); // Transmissão HTTP

      // Transmissão BLE para o aplicativo
      if (deviceConnected) {
        String payload = "Lat:" + String(gps.location.lat(), 6) + "|Lon:" + String(gps.location.lng(), 6) + "|Bat:" + String(lerBateriaPercentual()) + "%";
        pCharacteristic->setValue(payload.c_str());
        pCharacteristic->notify();
      }
    }
  }

  // -------------------------------------------------------------------
  // BLOCO 5: MÁQUINA DE ESTADOS DO LED RGB INDICADOR
  // -------------------------------------------------------------------
  if (!rastreamentoAtivo) {
    defineRGB(LOW, LOW, LOW); // Desligado em Standby
  } 
  else {
    if (!gps.location.isValid()) {
      defineRGB(HIGH, LOW, LOW); // Vermelho: Rastreamento ativo, buscando satélites
    } 
    else {
      if (piscaLEDVerde) {
        defineRGB(LOW, LOW, LOW); // Pulso rápido ao transmitir dados
        delay(80);
        piscaLEDVerde = false;
      }
      defineRGB(LOW, HIGH, LOW); // Verde: Fixo do GPS obtido e rastreamento ativo
    }
  }
}