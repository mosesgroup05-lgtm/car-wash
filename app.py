from flask import Flask, render_template, Response, request, jsonify
from ultralytics import YOLO
import cv2
import os
from datetime import datetime
import easyocr
import mysql.connector
import time
import re

# -----------------------------
# Flask App
# -----------------------------
app = Flask(__name__)

# -----------------------------
# Database Configuration (Render + Local)
# -----------------------------
DB_HOST = os.getenv("DB_HOST", "localhost")
DB_USER = os.getenv("DB_USER", "root")
DB_PASS = os.getenv("DB_PASS", "")
DB_NAME = os.getenv("DB_NAME", "car_detection")

try:
    db = mysql.connector.connect(
        host=DB_HOST,
        user=DB_USER,
        password=DB_PASS,
        database=DB_NAME
    )
    cursor = db.cursor(dictionary=True)
    print("✅ Connected to MySQL successfully")
except Exception as e:
    db = None
    cursor = None
    print(f"❌ Database connection failed: {e}")

# -----------------------------
# YOLO and OCR Models
# -----------------------------
try:
    vehicle_model = YOLO("yolov8n.pt")
    plate_model = YOLO("plate_detect.pt")
    reader = easyocr.Reader(['en'], gpu=False)
    print("✅ YOLO and OCR models loaded successfully")
except Exception as e:
    print(f"⚠️ YOLO/OCR initialization failed (Render likely has no GPU or video source): {e}")
    vehicle_model = None
    plate_model = None
    reader = None

# -----------------------------
# Folders & Video Source
# -----------------------------
os.makedirs("static/captures", exist_ok=True)
video_source = os.getenv("VIDEO_SOURCE", "home.mp4")
cap = None
if os.path.exists(video_source) or video_source.isnumeric():
    cap = cv2.VideoCapture(video_source)
else:
    print(f"⚠️ No video source found at '{video_source}' — live view disabled on Render")

# -----------------------------
# Configuration
# -----------------------------
VEHICLE_CLASSES = ['police', 'ambulance', 'bus', 'car', 'motorcycle', 'truck']
MIN_AREA = 141524
MAX_AREA = 141525

PRICES = {
    'car': 10000,
    'truck': 40000,
    'bus': 30000,
    'motorcycle': 5000,
    'police': 0,
    'ambulance': 0,
    'default': 10000
}

# -----------------------------
# Utility Functions
# -----------------------------
def normalize_plate(text):
    return ''.join(ch for ch in text.upper() if ch.isalnum())

def extract_plate_text(frame, x1, y1, x2, y2):
    if reader is None:
        return "Unknown"
    plate_crop = frame[y1:y2, x1:x2]
    if plate_crop.size == 0:
        return "Unknown"
    results = reader.readtext(plate_crop)
    text = " ".join(detected_text for (_, detected_text, conf) in results if conf > 0.4)
    return normalize_plate(text.strip()) if text else "Unknown"

def save_to_db(image, vehicle_type, plate, captured_time):
    if not cursor or plate == "Unknown":
        return
    sql = """
        INSERT INTO captures (image, car_type, plate, captured_time, status, payment_method)
        VALUES (%s, %s, %s, %s, %s, %s)
    """
    val = (image, vehicle_type, plate, captured_time, 'Unpaid', 'Not Paid')
    cursor.execute(sql, val)
    db.commit()
    print(f"✅ Saved {vehicle_type} with plate {plate}")

def get_video_fps():
    if not cap:
        return 30.0
    fps = cap.get(cv2.CAP_PROP_FPS)
    return fps if fps > 0 else 30.0

def get_stats():
    """Get dashboard statistics"""
    if not cursor:
        return {k: 0 for k in ['total_cars', 'paid_cars', 'unpaid_cars', 'momo_payments', 'cash_payments', 'revenue']}
    try:
        cursor.execute("SELECT COUNT(*) as total_cars FROM captures")
        total_cars = cursor.fetchone()['total_cars']
        cursor.execute("SELECT COUNT(*) as paid_cars FROM captures WHERE status LIKE 'Paid%'")
        paid_cars = cursor.fetchone()['paid_cars']
        cursor.execute("SELECT COUNT(*) as unpaid_cars FROM captures WHERE status = 'Unpaid'")
        unpaid_cars = cursor.fetchone()['unpaid_cars']
        cursor.execute("SELECT COUNT(*) as momo_payments FROM captures WHERE payment_method = 'MOMO'")
        momo_payments = cursor.fetchone()['momo_payments']
        cursor.execute("SELECT COUNT(*) as cash_payments FROM captures WHERE payment_method = 'CASH'")
        cash_payments = cursor.fetchone()['cash_payments']
        cursor.execute("SELECT status FROM captures WHERE status LIKE 'Paid%'")
        paid_rows = cursor.fetchall()
        revenue = 0
        for row in paid_rows:
            if row['status'] and row['status'].startswith('Paid'):
                numbers = re.findall(r'\d+', row['status'].replace(',', ''))
                if numbers:
                    revenue += int(numbers[0])
        return {
            'total_cars': total_cars,
            'paid_cars': paid_cars,
            'unpaid_cars': unpaid_cars,
            'momo_payments': momo_payments,
            'cash_payments': cash_payments,
            'revenue': revenue
        }
    except Exception as e:
        print(f"⚠️ Stats error: {e}")
        return {k: 0 for k in ['total_cars', 'paid_cars', 'unpaid_cars', 'momo_payments', 'cash_payments', 'revenue']}

# -----------------------------
# Frame Generator
# -----------------------------
def gen_frames():
    if not cap or not vehicle_model:
        print("⚠️ gen_frames() skipped (no video/model available)")
        while True:
            time.sleep(1)
            yield (b'--frame\r\nContent-Type: image/jpeg\r\n\r\n\r\n')
    video_fps = get_video_fps()
    frame_interval = 1.0 / video_fps
    while True:
        start_time = time.time()
        success, frame = cap.read()
        if not success:
            break
        results = vehicle_model.predict(frame, conf=0.4, verbose=False)
        annotated = frame.copy()
        for box in results[0].boxes:
            cls_id = int(box.cls[0])
            label = vehicle_model.names[cls_id].lower()
            if label in VEHICLE_CLASSES:
                x1, y1, x2, y2 = map(int, box.xyxy[0])
                width, height = x2 - x1, y2 - y1
                area = width * height
                if MIN_AREA <= area <= MAX_AREA:
                    vehicle_crop = frame[y1:y2, x1:x2]
                    plate_results = plate_model.predict(vehicle_crop, conf=0.5, verbose=False)
                    plate_text = "Unknown"
                    for pbox in plate_results[0].boxes:
                        px1, py1, px2, py2 = map(int, pbox.xyxy[0])
                        plate_text = extract_plate_text(vehicle_crop, px1, py1, px2, py2)
                    timestamp = datetime.now()
                    formatted_time = timestamp.strftime("%Y-%m-%d %H:%M:%S")
                    filename = f"static/captures/{label}_{timestamp.strftime('%Y%m%d_%H%M%S')}.jpg"
                    cv2.imwrite(filename, vehicle_crop)
                    save_to_db(filename, label.title(), plate_text, formatted_time)
                    cv2.rectangle(annotated, (x1, y1), (x2, y2), (0, 255, 0), 3)
                    cv2.putText(annotated, f"{label.title()} - {plate_text}",
                                (x1, y1 - 10), cv2.FONT_HERSHEY_SIMPLEX, 0.8, (0, 255, 0), 2)
        _, buffer = cv2.imencode('.jpg', annotated)
        yield (b'--frame\r\nContent-Type: image/jpeg\r\n\r\n' + buffer.tobytes() + b'\r\n')
        time.sleep(max(0, frame_interval - (time.time() - start_time)))

# -----------------------------
# Routes
# -----------------------------
@app.route('/')
def welcome():
    return render_template('welcome.html')

@app.route('/index')
def index():
    if not cursor:
        return "Database not connected", 500
    cursor.execute("SELECT * FROM captures ORDER BY id DESC LIMIT 10")
    vehicles = cursor.fetchall()
    return render_template('index.html', cars=vehicles, stats=get_stats())

@app.route('/history')
def history():
    cursor.execute("SELECT * FROM captures ORDER BY id DESC")
    return render_template('history.html', cars=cursor.fetchall(), stats=get_stats())

@app.route('/live')
def live():
    return render_template('live.html')

@app.route('/video_feed')
def video_feed():
    return Response(gen_frames(), mimetype='multipart/x-mixed-replace; boundary=frame')

@app.route('/process_payment', methods=['POST'])
def process_payment():
    try:
        data = request.get_json()
        vehicle_id, method, amount = data.get('id'), data.get('method'), data.get('amount')
        status_text = f"Paid FRW {amount:,}"
        cursor.execute("UPDATE captures SET status=%s, payment_method=%s WHERE id=%s",
                       (status_text, method, vehicle_id))
        db.commit()
        return jsonify({'success': True, 'status': status_text})
    except Exception as e:
        print(f"Payment error: {e}")
        return jsonify({'success': False, 'error': str(e)})

@app.route('/update_plate', methods=['POST'])
def update_plate():
    try:
        data = request.get_json()
        cursor.execute("UPDATE captures SET plate=%s WHERE id=%s", (data.get('plate'), data.get('id')))
        db.commit()
        return jsonify({'success': True})
    except Exception as e:
        print(f"Plate update error: {e}")
        return jsonify({'success': False, 'error': str(e)})

@app.route('/delete_vehicle', methods=['POST'])
def delete_vehicle():
    try:
        data = request.get_json()
        vehicle_id = data.get('id')
        cursor.execute("SELECT image FROM captures WHERE id=%s", (vehicle_id,))
        result = cursor.fetchone()
        if result and os.path.exists(result['image']):
            os.remove(result['image'])
        cursor.execute("DELETE FROM captures WHERE id=%s", (vehicle_id,))
        db.commit()
        return jsonify({'success': True})
    except Exception as e:
        print(f"Delete error: {e}")
        db.rollback()
        return jsonify({'success': False, 'error': str(e)})

@app.route('/get_stats')
def get_stats_route():
    return jsonify(get_stats())

# -----------------------------
# Database Initialization
# -----------------------------
def init_database():
    if not cursor:
        print("⚠️ Skipping DB init (no connection)")
        return
    cursor.execute("""
        CREATE TABLE IF NOT EXISTS captures (
            id INT AUTO_INCREMENT PRIMARY KEY,
            image VARCHAR(255) NOT NULL,
            car_type VARCHAR(50),
            plate VARCHAR(50),
            captured_time DATETIME DEFAULT CURRENT_TIMESTAMP,
            status VARCHAR(50) DEFAULT 'Unpaid',
            payment_method VARCHAR(50) DEFAULT 'Not Paid'
        )
    """)
    db.commit()
    print("✅ Database initialized")

# -----------------------------
# Run the App
# -----------------------------
if __name__ == "__main__":
    print("🚗 Vehicle detection app starting...")
    init_database()
    app.run(host="0.0.0.0", port=int(os.getenv("PORT", 5000)), debug=True)
