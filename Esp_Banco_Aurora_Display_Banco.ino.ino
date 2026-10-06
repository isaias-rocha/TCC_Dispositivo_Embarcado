/* =====================================================================
   Aurora GPS - ESP32 (LED RGB, SOS, Bateria TP4056 e Status)
   ===================================================================== */

// --- CONECTIVIDADE (Wi-Fi e Banco) ---
#include <WiFi.h>             // Conexão de rede Wi-Fi
#include <HTTPClient.h>       // Requisições HTTP para a API/Banco

// --- RASTREAMENTO (GPS) ---
#include <TinyGPS++.h>        // Interpretação dos dados do módulo GPS

// --- DISPLAY (OLED) ---
#include <Wire.h>             // Comunicação I2C (usada pelo display)
#include <Adafruit_GFX.h>     // Motor gráfico para desenhar formas e textos
#include <Adafruit_SSD1306.h> // Driver específico do modelo de display OLED

// --- BLUETOOTH (BLE) ---
#include <BLEDevice.h>        // Gerenciamento geral do dispositivo Bluetooth
#include <BLEServer.h>        // Criação do servidor para o celular conectar
#include <BLEUtils.h>         // Utilitários auxiliares do Bluetooth
#include <BLE2902.h>          // Permite enviar notificações ativas para o app

// ---------------------------------------------------------------------
// CONFIGURAÇÕES DO DISPLAY OLED (128x64)
// ---------------------------------------------------------------------
#define SCREEN_WIDTH 128
#define SCREEN_HEIGHT 64
#define OLED_RESET    -1
Adafruit_SSD1306 display(SCREEN_WIDTH, SCREEN_HEIGHT, &Wire, OLED_RESET);

// ---------------------------------------------------------------------
// BITMAPS DOS ÍCONES DA BARRA DE STATUS (8x8 pixels)
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
// CONFIGURAÇÕES DO BLE
// ---------------------------------------------------------------------
#define SERVICE_UUID        "4fafc201-1fb5-459e-8fcc-c5c9c331914b"
#define CHARACTERISTIC_UUID "beb5483e-36e1-4688-b7f5-ea07361b26a8"

BLEServer* pServer = NULL;
BLECharacteristic* pCharacteristic = NULL;
bool deviceConnected = false;
bool oldDeviceConnected = false;

class MyServerCallbacks: public BLEServerCallbacks {
    void onConnect(BLEServer* pServer) override {
      deviceConnected = true;
    }
    void onDisconnect(BLEServer* pServer) override {
      deviceConnected = false;
    }
};

// ---------------------------------------------------------------------
// CONFIGURAÇÕES DE REDE, PINOS E Componentes
// ---------------------------------------------------------------------
const char* WIFI_SSID   = "Aurora";
const char* WIFI_SENHA  = "equipe12345678";
const char* API_URL     = "http://192.168.1.9/api_arduino/gravar.php";
const char* API_KEY     = "arduino-gps-2026";

const char* DEVICE_NOME   = "Aurora GPS";
const char* DEVICE_MODELO = "ESP32 + NEO-6M";
const char* FIRMWARE      = "1.0.0";

const unsigned long INTERVALO_ENVIO   = 5000;  // Envio a cada 5s
const unsigned long TEMPO_PRESS_SOS   = 3000;  // 3s para SOS

const int pinoBotao   = 4;
const int pinR        = 5;
const int pinG        = 18;
const int pinB        = 19;
const int pinoBateria = 34; // GPIO 34 ADC1
const int FUSO_HORARIO = -3;

TinyGPSPlus gps;
HardwareSerial ss(2);

// ESTADOS DO SISTEMA
bool rastreamentoAtivo = false;
bool piscaLEDVerde = false;

// CONTROLE DO BOTÃO E TEMPOS
unsigned long tempoInicioPressionado = 0;
bool sosDisparado = false;
unsigned long tempoExibicaoSOS = 0;
bool mostrandoTelaSOS = false;

unsigned long ultimoEnvio = 0;
unsigned long ultimaAttDisplay = 0;

String deviceUid;

void defineRGB(bool r, bool g, bool b) {
  digitalWrite(pinR, r);
  digitalWrite(pinG, g);
  digitalWrite(pinB, b);
}

String montarDeviceUid() {
  uint64_t mac = ESP.getEfuseMac();
  char buf[24];
  snprintf(buf, sizeof(buf), "ESP32-%04X%08X", (uint16_t)(mac >> 32), (uint32_t)mac);
  return String(buf);
}

String montarDataHora() {
  if (!gps.date.isValid() || !gps.time.isValid()) return "";
  int ano  = gps.date.year();
  int mes  = gps.date.month();
  int dia  = gps.date.day();
  int hora = gps.time.hour() + FUSO_HORARIO;

  if (hora < 0) { hora += 24; dia--; }
  if (dia < 1)  { mes--; dia = 30; } 
  if (mes < 1)  { mes = 12; ano--; }

  char buf[24];
  snprintf(buf, sizeof(buf), "%04d-%02d-%02d %02d:%02d:%02d",
           ano, mes, dia, hora, gps.time.minute(), gps.time.second());
  return String(buf);
}

// ---------------------------------------------------------------------
// FUNÇÕES DE LEITURA E DESENHO DA BATERIA
// ---------------------------------------------------------------------
int lerBateriaPercentual() {
  int leituraADC = analogRead(pinoBateria);
  
  // Tensão de entrada (ADC = 0 a 4095) considerando divisor por 2
  float tensao = (leituraADC / 4095.0) * 3.3 * 2.0; 

  // Mapeia de 3.3V (0%) até 4.2V (100% Carga Máxima Li-Ion)
  int percentual = map((int)(tensao * 100), 330, 420, 0, 100);
  return constrain(percentual, 0, 100);
}

void desenharBateria(int x, int y, int percentual) {
  // Corpo da Bateria (L:14px, A:8px)
  display.drawRect(x, y, 14, 8, WHITE);
  // Polo Positivo (+)
  display.fillRect(x + 14, y + 2, 2, 4, WHITE);
  
  // Preenchimento proporcional
  int larguraBarra = map(constrain(percentual, 0, 100), 0, 100, 0, 10);
  if (larguraBarra > 0) {
    display.fillRect(x + 2, y + 2, larguraBarra, 4, WHITE);
  }
}

// ---------------------------------------------------------------------
// DESENHO NO DISPLAY OLED
// ---------------------------------------------------------------------
void atualizarOLED() {
  display.clearDisplay();
  display.setTextSize(1);
  display.setTextColor(WHITE);

  // 1. ÍCONE WI-FI
  if (WiFi.status() == WL_CONNECTED) {
    display.drawBitmap(0, 1, icon_wifi, 8, 8, WHITE);
  } else {
    display.setCursor(0, 1);
    display.print("x");
  }

  // 2. ÍCONE BLUETOOTH
  if (deviceConnected) {
    display.drawBitmap(12, 1, icon_bt, 8, 8, WHITE);
  }

  // 3. HORÁRIO (HH:MM)
  String dataHora = montarDataHora();
  if (dataHora != "") {
    String horaStr = dataHora.substring(11, 16); // Formato HH:MM
    display.setCursor(26, 1);
    display.print(horaStr);
  } else {
    display.setCursor(26, 1);
    display.print("--:--");
  }

  // 4. BATERIA (Porcentagem + Ícone Gráfico)
  int pctBateria = lerBateriaPercentual();
  display.setCursor(76, 1);
  if(pctBateria < 100) display.print(" ");
  display.print(pctBateria);
  display.print("%");
  
  desenharBateria(108, 1, pctBateria);

  // LINHA DIVISÓRIA DA BARRA DE STATUS
  display.drawLine(0, 11, 128, 11, WHITE); 

  // TELA DE SOS
  if (mostrandoTelaSOS) {
    display.setTextSize(1);
    
    // Centraliza "SOS" na tela
    display.setCursor(55, 25);
    display.println("SOS");
    
    // Centraliza "ENVIADO!" logo abaixo
    display.setCursor(40, 37);
    display.println("ENVIADO!");
    
    display.display();
    return;
  }

  // CORPO DO DISPLAY
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
// REQUISIÇÃO HTTP POST PARA O SERVIDOR PHP/MYSQL
// ---------------------------------------------------------------------
bool enviarParaAPI(bool isSOS = false) {
  if (WiFi.status() != WL_CONNECTED) {
    Serial.println("[API] Erro: Dispositivo desconectado do Wi-Fi!");
    return false;
  }

  String dataHora = montarDataHora();

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
  json += "\"data_hora_gps\":\"" + (dataHora != "" ? dataHora : "N/A") + "\"}";

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

void dispararAlertaSOS() {
  mostrandoTelaSOS = true;
  tempoExibicaoSOS = millis();
  
  defineRGB(HIGH, HIGH, HIGH); // LED Pisca
  enviarParaAPI(true);

  if (deviceConnected) {
    String payload = "ALERT:SOS|Lat:" + String(gps.location.lat(), 6) + "|Lon:" + String(gps.location.lng(), 6);
    pCharacteristic->setValue(payload.c_str());
    pCharacteristic->notify();
  }
  atualizarOLED();
}

void setup() {
  Serial.begin(115200);
  ss.begin(9600, SERIAL_8N1, 16, 17);

  pinMode(pinoBotao, INPUT_PULLUP);
  pinMode(pinR, OUTPUT);
  pinMode(pinG, OUTPUT);
  pinMode(pinB, OUTPUT);
  pinMode(pinoBateria, INPUT);

  defineRGB(HIGH, HIGH, HIGH); // LED Boot

  deviceUid = montarDeviceUid();

  if(!display.begin(SSD1306_SWITCHCAPVCC, 0x3C)) {
    Serial.println("Falha no OLED");
  }
  display.clearDisplay();
  display.setTextSize(2);
  display.setTextColor(WHITE);
  display.setCursor(5, 20);
  display.println("Aurora GPS");
  display.display();

  // Configuração BLE
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

  pCharacteristic->addDescriptor(new BLE2902());
  pService->start();

  BLEAdvertising *pAdvertising = BLEDevice::getAdvertising();
  pAdvertising->addServiceUUID(SERVICE_UUID);
  pAdvertising->setScanResponse(true);
  pAdvertising->setMinPreferred(0x0);
  BLEDevice::startAdvertising();

  // Conexão Wi-Fi com Aguardo / Loop Bloqueante
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

void loop() {
  while (ss.available() > 0) {
    gps.encode(ss.read());
  }

  // LEITURA DO BOTÃO (Clique Curto vs Longo 3s SOS)
  int leituraBotao = digitalRead(pinoBotao);

  if (leituraBotao == LOW) {
    if (tempoInicioPressionado == 0) {
      tempoInicioPressionado = millis();
    }

    if (!sosDisparado && (millis() - tempoInicioPressionado >= TEMPO_PRESS_SOS)) {
      sosDisparado = true;
      dispararAlertaSOS();
    }
  } 
  else {
    if (tempoInicioPressionado > 0) {
      unsigned long duracaoPressionado = millis() - tempoInicioPressionado;

      if (duracaoPressionado < TEMPO_PRESS_SOS && duracaoPressionado > 50) {
        if (!sosDisparado) {
          rastreamentoAtivo = !rastreamentoAtivo;
          atualizarOLED();
        }
      }
      tempoInicioPressionado = 0;
      sosDisparado = false;
    }
  }

  // Tira aviso SOS após 3s
  if (mostrandoTelaSOS && (millis() - tempoExibicaoSOS > 3000)) {
    mostrandoTelaSOS = false;
    atualizarOLED();
  }

  // Reconexão BLE
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

  // Atualização periódica da Tela (1s)
  if (millis() - ultimaAttDisplay > 1000) {
    ultimaAttDisplay = millis();
    atualizarOLED();
  }

  // ENVIO AUTOMÁTICO A CADA 5 SEGUNDOS
  if (rastreamentoAtivo && gps.location.isValid()) {
    if (millis() - ultimoEnvio >= INTERVALO_ENVIO) {
      ultimoEnvio = millis();
      piscaLEDVerde = true;
      
      enviarParaAPI(false);

      if (deviceConnected) {
        String payload = "Lat:" + String(gps.location.lat(), 6) + "|Lon:" + String(gps.location.lng(), 6) + "|Bat:" + String(lerBateriaPercentual()) + "%";
        pCharacteristic->setValue(payload.c_str());
        pCharacteristic->notify();
      }
    }
  }

  // GERENCIAMENTO DO LED RGB
  if (!rastreamentoAtivo) {
    defineRGB(LOW, LOW, LOW);  // Desligado em Standby
  } 
  else {
    if (!gps.location.isValid()) {
      defineRGB(HIGH, LOW, LOW); // VERMELHO: Buscando GPS
    } 
    else {
      if (piscaLEDVerde) {
        defineRGB(LOW, LOW, LOW); // Pulso rápido off no envio de dados
        delay(80);
        piscaLEDVerde = false;
      }
      defineRGB(LOW, HIGH, LOW); // VERDE: Rastreamento OK
    }
  }
}