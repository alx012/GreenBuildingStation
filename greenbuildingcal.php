<?php
// 檢查會話是否已啟動，如果沒有則啟動會話
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title data-i18n="greenBuildingTitle">綠建築計算</title>
    
    <!-- 引入 Bootstrap 5 -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" />
    <link rel="stylesheet" href="your-existing-styles.css" />

    <style>
        body {
            margin-top: 50px; /* 確保 navbar 不會擋住主內容 */
            padding: 0;
            background-image: url('https://i.imgur.com/WJGtbFT.jpeg');
            background-color: rgba(255, 255, 255, 0.8);
            background-size: 100% 100%; /* 使背景圖片填滿整個背景區域 */
            background-position: center; /* 背景圖片居中 */
            background-repeat: no-repeat; /* 不重複背景圖片 */
            background-attachment: fixed; /* 背景固定在視口上 */
        }

        .navbar-brand {
            font-weight: bold;
            }

        #container {
            display: flex;
            flex-direction: column;
            align-items: flex-start; /* 讓內容靠左對齊 */
            max-width: 70%;
            margin: 0 auto;
            padding: 20px;
        }

        #buildingContainer {
            max-width: 70%; /* 調整最大寬度，避免內容過寬 */
            margin: 0 auto; /* 讓內容在螢幕中央 */
            padding: 20px; /* 增加內邊距，避免太靠邊 */
        }

        .floor, .unit, .room {
            border: 1px solid #000;
            margin: 10px 0;
            padding: 10px;
            border-radius: 10px;
            display: flex;
            flex-direction: column;
        }

            .floor:nth-child(odd) {
                background-color: rgba(191, 202, 194, 0.7); /* 第一種顏色，透明度70% */
            }

            .floor:nth-child(even) {
                background-color: rgba(235, 232, 227, 0.7); /* 第二種顏色，透明度70% */
            }

        .header-row {
            font-weight: bold;
            display: flex;
            justify-content: space-between;
        }

            .header-row div {
                flex: 1;
                text-align: center;
                padding: 5px;
                border-bottom: 1px solid #000;
            }

        .room-row {
            display: flex;
            justify-content: space-between;
        }

            .room-row input {
                flex: 1;
                margin: 5px;
                padding: 5px;
            }

        button {
            margin-top: 10px;
            padding: 10px;
            border-radius: 12px;
            background-color: #4CAF50;
            color: white;
            border: none;
            cursor: pointer;
        }

            button:hover {
                background-color: #45a049;
            }

        #modal, #deleteModal, #copyModal {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.5);
            overflow: auto; /* 允許整個模態框區域滾動 */
        }

        .modal-content {
            background-color: #fff;
            margin: 5% auto; /* 調整上邊距，讓模態框更靠上 */
            padding: 20px;
            border-radius: 10px;
            width: 60%;
            max-width: 800px;
            max-height: 80vh; /* 設置最大高度為視窗高度的80% */
            overflow-y: auto; /* 允許內容滾動 */
            position: relative; /* 為了固定標題 */
        }

        .sub-modal-content {
            display: none;
            margin-top: 20px;
        }

        #fixed-buttons {
            position: fixed;
            top: 200px; /* 固定在上方 */
            left: 50px; /* 固定在左側 */
            background-color: rgba(255, 255, 255, 0.8);
            padding: 10px;
            border-radius: 5px;
            box-shadow: 0 2px 5px rgba(0, 0, 0, 0.1);
            z-index: 1000;
            display: flex; /* 使用 flexbox */
            flex-direction: column; /* 垂直排列按鈕 */
        }

            #fixed-buttons button {
                margin: 5px 0; /* 上下間距 */
            }

        .modal-header {
            position: sticky;
            top: 0;
            background-color: #fff;
            padding: 10px 0;
            margin-bottom: 15px;
            border-bottom: 1px solid #ddd;
            z-index: 1;
        }

        .sub-modal-content {
            display: none;
            margin-top: 20px;
            padding: 15px;
            background-color: #f9f9f9;
            border-radius: 8px;
            overflow-y: auto;
        }

        .copy-select, .copy-input {
            margin: 8px 0;
            width: 100%;
            padding: 8px;
            border: 1px solid #ddd;
            border-radius: 4px;
            box-sizing: border-box;
        }

        .copy-label {
            display: block;
            margin-top: 12px;
            margin-bottom: 4px;
            font-weight: bold;
            color: #333;
        }

        /* 優化按鈕組樣式 */
        .button-group {
            display: flex;
            gap: 10px;
            margin-top: 15px;
            padding-top: 15px;
            border-top: 1px solid #ddd;
            position: sticky;
            bottom: 0;
            background-color: #fff;
        }

        .button-group button {
            flex: 1;
            margin: 0;
        }

        /* 添加分隔線 */
        .divider {
            height: 1px;
            background-color: #ddd;
            margin: 15px 0;
        }

        /* 新增的樣式 */
        .copy-select {
            margin: 10px 0;
            width: 100%;
            padding: 5px;
        }

        .copy-label {
            display: block;
            margin-top: 10px;
            font-weight: bold;
        }

        /* 導覽列背景顏色 */
        .custom-navbar {
        background-color: #769a76; /* 這裡可以換成你要的顏色 */
        }

    </style>
</head>
<body>
    <?php include('navbar.php'); ?>

    <div id="container">
        <h1 data-i18n="greenBuildingCalc">綠建築計算</h1>
        <p data-i18n="greenBuildingDesc">在這裡進行綠建築計算的內容。</p>
    </div>

    <div id="fixed-buttons">
        <button onclick="showModal()" data-i18n="add">新增</button>
        <button onclick="showCopyModal()" data-i18n="copy">複製</button>
        <button onclick="showDeleteModal()" data-i18n="delete">刪除</button>
        <button onclick="save()" data-i18n="save">儲存</button>
        <button id="calculateButton" onclick="calculate()" data-i18n="calculate">計算</button>
    </div>

    <div id="buildingContainer">
        <div class="floor" id="floor1">
            <h3><span data-i18n="floor">樓層</span> 1</h3>
            <div class="unit" id="floor1_unit1">
                <h4><span data-i18n="unit">單位</span> 1</h4>
                <div class="header-row">
                    <div data-i18n="roomNumber">房間號碼</div>
                    <div data-i18n="height">高度</div>
                    <div data-i18n="length">長度</div>
                    <div data-i18n="depth">深度</div>
                    <div data-i18n="windowPosition">窗戶位置</div>
                </div>
                <div class="room-row" id="floor1_unit1_room1">
                    <input type="text" data-i18n-placeholder="roomNumberPlaceholder" value="1" />
                    <input type="text" data-i18n-placeholder="heightPlaceholder" />
                    <input type="text" data-i18n-placeholder="lengthPlaceholder" />
                    <input type="text" data-i18n-placeholder="depthPlaceholder" />
                    <input type="text" data-i18n-placeholder="windowPositionPlaceholder" />
                </div>
            </div>
        </div>
    </div>


<!-- Add Modal -->
<div id="modal">
    <div class="modal-content">
        <h2 data-i18n="selectOptionAdd">Select an option to add:</h2>
        <button onclick="showAddFloor()" data-i18n="addFloor">Add Floor</button>
        <button onclick="showAddUnit()" data-i18n="addUnit">Add Unit</button>
        <button onclick="showAddRoom()" data-i18n="addRoom">Add Room</button>
        <button onclick="closeModal()" data-i18n="cancel">Cancel</button>

        <div class="sub-modal-content" id="addFloorContent">
            <h3 data-i18n="addFloorTitle">Add Floor</h3>
            <p data-i18n="floorAddedSuccess">Floor added successfully!</p>
            <button onclick="addFloor()" data-i18n="confirm">Confirm</button>
            <button onclick="closeSubModal('addFloorContent')" data-i18n="cancel">Cancel</button>
        </div>

        <div class="sub-modal-content" id="addUnitContent">
            <h3 data-i18n="addUnitTitle">Add Unit</h3>
            <label for="unitFloorSelect" data-i18n="selectFloor">Select Floor:</label>
            <select id="unitFloorSelect" onchange="updateUnitNumber()"></select>
            <label for="unitNumber" data-i18n="unitNumber">Unit Number:</label>
            <input type="number" id="unitNumber" min="1" value="1">
            <button onclick="addUnitPrompt()" data-i18n="confirm">Confirm</button>
            <button onclick="closeSubModal('addUnitContent')" data-i18n="cancel">Cancel</button>
        </div>

        <div class="sub-modal-content" id="addRoomContent">
            <h3 data-i18n="addRoomTitle">Add Room</h3>
            <label for="roomFloorSelect" data-i18n="selectFloor">Select Floor:</label>
            <select id="roomFloorSelect" onchange="updateRoomUnitSelect()"></select>
            <label for="roomUnitSelect" data-i18n="selectUnit">Select Unit:</label>
            <select id="roomUnitSelect"></select>
            <button onclick="addRoomPrompt()" data-i18n="confirm">Confirm</button>
            <button onclick="closeSubModal('addRoomContent')" data-i18n="cancel">Cancel</button>
        </div>
    </div>
</div>

<!-- Delete Modal -->
<div id="deleteModal">
    <div class="modal-content">
        <h2 data-i18n="selectOptionDelete">Select an option to delete:</h2>
        <button onclick="showDeleteFloor()" data-i18n="deleteFloor">Delete Floor</button>
        <button onclick="showDeleteUnit()" data-i18n="deleteUnit">Delete Unit</button>
        <button onclick="showDeleteRoom()" data-i18n="deleteRoom">Delete Room</button>
        <button onclick="closeDeleteModal()" data-i18n="cancel">Cancel</button>

        <div class="sub-modal-content" id="deleteFloorContent">
            <h3 data-i18n="deleteFloorTitle">Delete Floor</h3>
            <label for="deleteFloorSelect" data-i18n="selectFloor">Select Floor:</label>
            <select id="deleteFloorSelect"></select>
            <button onclick="deleteFloor()" data-i18n="confirm">Confirm</button>
            <button onclick="closeSubModal('deleteFloorContent')" data-i18n="cancel">Cancel</button>
        </div>

        <div class="sub-modal-content" id="deleteUnitContent">
            <h3 data-i18n="deleteUnitTitle">Delete Unit</h3>
            <label for="deleteUnitFloorSelect" data-i18n="selectFloor">Select Floor:</label>
            <select id="deleteUnitFloorSelect" onchange="updateDeleteUnitSelect()"></select>
            <label for="deleteUnitSelect" data-i18n="selectUnit">Select Unit:</label>
            <select id="deleteUnitSelect"></select>
            <button onclick="deleteUnit()" data-i18n="confirm">Confirm</button>
            <button onclick="closeSubModal('deleteUnitContent')" data-i18n="cancel">Cancel</button>
        </div>

        <div class="sub-modal-content" id="deleteRoomContent">
            <h3 data-i18n="deleteRoomTitle">Delete Room</h3>
            <label for="deleteRoomFloorSelect" data-i18n="selectFloor">Select Floor:</label>
            <select id="deleteRoomFloorSelect" onchange="updateDeleteRoomUnitSelect()"></select>
            <label for="deleteRoomUnitSelect" data-i18n="selectUnit">Select Unit:</label>
            <select id="deleteRoomUnitSelect" onchange="updateDeleteRoomSelect()"></select>
            <label for="deleteRoomSelect" data-i18n="selectRoom">Select Room:</label>
            <select id="deleteRoomSelect"></select>
            <button onclick="deleteRoom()" data-i18n="confirm">Confirm</button>
            <button onclick="closeSubModal('deleteRoomContent')" data-i18n="cancel">Cancel</button>
        </div>
    </div>
</div>

<!-- Copy Modal -->
<div id="copyModal">
    <div class="modal-content">
        <div class="modal-header">
            <h2 data-i18n="selectOptionCopy">Select what to copy:</h2>
        </div>

        <button onclick="showCopyFloor()" data-i18n="copyFloor">Copy Floor</button>
        <button onclick="showCopyUnit()" data-i18n="copyUnit">Copy Unit</button>
        <button onclick="showCopyRoom()" data-i18n="copyRoom">Copy Room</button>

        <div class="divider"></div>

        <div class="sub-modal-content" id="copyFloorContent">
            <h3 data-i18n="copyFloorTitle">Copy Floor</h3>
            <label class="copy-label" data-i18n="sourceFloor">Source Floor:</label>
            <select id="sourceFloorSelect" class="copy-select"></select>

            <label class="copy-label" data-i18n="targetFloorNumber">Target Floor Number:</label>
            <input type="number" id="targetFloorNumber" class="copy-select" min="1">

            <div class="button-group">
                <button onclick="copyFloor()" data-i18n="copy">Copy</button>
                <button onclick="closeSubModal('copyFloorContent')" data-i18n="cancel">Cancel</button>
            </div>
        </div>

        <div class="sub-modal-content" id="copyUnitContent">
            <h3 data-i18n="copyUnitTitle">Copy Unit</h3>
            <label class="copy-label" data-i18n="sourceFloor">Source Floor:</label>
            <select id="sourceUnitFloorSelect" class="copy-select" onchange="updateSourceUnitSelect()"></select>

            <label class="copy-label" data-i18n="sourceUnit">Source Unit:</label>
            <select id="sourceUnitSelect" class="copy-select"></select>

            <label class="copy-label" data-i18n="targetFloor">Target Floor:</label>
            <select id="targetUnitFloorSelect" class="copy-select"></select>

            <label class="copy-label" data-i18n="targetUnitNumber">Target Unit Number:</label>
            <input type="number" id="targetUnitNumber" class="copy-select" min="1">

            <div class="button-group">
                <button onclick="copyUnit()" data-i18n="copy">Copy</button>
                <button onclick="closeSubModal('copyUnitContent')" data-i18n="cancel">Cancel</button>
            </div>
        </div>

        <div class="sub-modal-content" id="copyRoomContent">
            <h3 data-i18n="copyRoomTitle">Copy Room</h3>
            <label class="copy-label" data-i18n="sourceFloor">Source Floor:</label>
            <select id="sourceRoomFloorSelect" class="copy-select" onchange="updateSourceRoomUnitSelect()"></select>

            <label class="copy-label" data-i18n="sourceUnit">Source Unit:</label>
            <select id="sourceRoomUnitSelect" class="copy-select" onchange="updateSourceRoomSelect()"></select>

            <label class="copy-label" data-i18n="sourceRoom">Source Room:</label>
            <select id="sourceRoomSelect" class="copy-select"></select>

            <label class="copy-label" data-i18n="targetFloor">Target Floor:</label>
            <select id="targetRoomFloorSelect" class="copy-select" onchange="updateTargetRoomUnitSelect()"></select>

            <label class="copy-label" data-i18n="targetUnit">Target Unit:</label>
            <select id="targetRoomUnitSelect" class="copy-select"></select>

            <div class="button-group">
                <button onclick="copyRoom()" data-i18n="copy">Copy</button>
                <button onclick="closeSubModal('copyRoomContent')" data-i18n="cancel">Cancel</button>
            </div>
        </div>

        <button onclick="closeCopyModal()" style="margin-top: 15px; width: 100%;" data-i18n="close">Close</button>
    </div>
</div>
    
    <script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js"></script>

    <script>
        let floorCount = 1;
        let maxFloorNumber = 1;  // 新增這行
        let unitCounts = { 'floor1': 1 };
        let roomCounts = { 'floor1_unit1': 1 };
        let deletedFloors = [];
        let deletedUnits = {};
        let deletedRooms = {};

        function showModal() {
            document.getElementById('modal').style.display = 'block';
        }

        function closeModal() {
            document.getElementById('modal').style.display = 'none';
            hideAllSubModals();
        }

        function showDeleteModal() {
            document.getElementById('deleteModal').style.display = 'block';
        }

        function closeDeleteModal() {
            document.getElementById('deleteModal').style.display = 'none';
            hideAllSubModals();
        }

        function showCopyModal() {
            document.getElementById('copyModal').style.display = 'block';
        }

        function closeCopyModal() {
            document.getElementById('copyModal').style.display = 'none';
            hideAllSubModals();
        }

        function showAddFloor() {
            hideAllSubModals();
            document.getElementById('addFloorContent').style.display = 'block';
        }

        function showAddUnit() {
            hideAllSubModals();
            const select = document.getElementById('unitFloorSelect');
            select.innerHTML = '';
            document.querySelectorAll('.floor').forEach(floor => {
                const option = document.createElement('option');
                option.value = floor.id;
                option.textContent = floor.querySelector('h3').textContent;
                select.appendChild(option);
            });
            updateUnitNumber();
            document.getElementById('addUnitContent').style.display = 'block';
        }

        function updateUnitNumber() {
            const floorId = document.getElementById('unitFloorSelect').value;
            const unitNumber = document.getElementById('unitNumber');
            unitNumber.value = (unitCounts[floorId] || 0) + 1;
        }

        function showAddRoom() {
            hideAllSubModals();
            const floorSelect = document.getElementById('roomFloorSelect');
            floorSelect.innerHTML = '';
            document.querySelectorAll('.floor').forEach(floor => {
                const option = document.createElement('option');
                option.value = floor.id;
                option.textContent = floor.querySelector('h3').textContent;
                floorSelect.appendChild(option);
            });
            updateRoomUnitSelect();
            document.getElementById('addRoomContent').style.display = 'block';
        }

        function updateRoomUnitSelect() {
            const floorId = document.getElementById('roomFloorSelect').value;
            const unitSelect = document.getElementById('roomUnitSelect');
            unitSelect.innerHTML = '';
            document.querySelectorAll(`#${floorId} .unit`).forEach(unit => {
                const option = document.createElement('option');
                option.value = unit.id;
                option.textContent = unit.querySelector('h4').textContent;
                unitSelect.appendChild(option);
            });
        }

        function showDeleteFloor() {
            hideAllSubModals();
            const select = document.getElementById('deleteFloorSelect');
            select.innerHTML = '';
            document.querySelectorAll('.floor').forEach(floor => {
                const option = document.createElement('option');
                option.value = floor.id;
                option.textContent = floor.querySelector('h3').textContent;
                select.appendChild(option);
            });
            document.getElementById('deleteFloorContent').style.display = 'block';
        }

        function showDeleteUnit() {
            hideAllSubModals();
            const floorSelect = document.getElementById('deleteUnitFloorSelect');
            floorSelect.innerHTML = '';
            document.querySelectorAll('.floor').forEach(floor => {
                const option = document.createElement('option');
                option.value = floor.id;
                option.textContent = floor.querySelector('h3').textContent;
                floorSelect.appendChild(option);
            });
            updateDeleteUnitSelect();
            document.getElementById('deleteUnitContent').style.display = 'block';
        }

        function updateDeleteUnitSelect() {
            const floorId = document.getElementById('deleteUnitFloorSelect').value;
            const unitSelect = document.getElementById('deleteUnitSelect');
            unitSelect.innerHTML = '';
            document.querySelectorAll(`#${floorId} .unit`).forEach(unit => {
                const option = document.createElement('option');
                option.value = unit.id;
                option.textContent = unit.querySelector('h4').textContent;
                unitSelect.appendChild(option);
            });
        }

        function showDeleteRoom() {
            hideAllSubModals();
            const floorSelect = document.getElementById('deleteRoomFloorSelect');
            floorSelect.innerHTML = '';
            document.querySelectorAll('.floor').forEach(floor => {
                const option = document.createElement('option');
                option.value = floor.id;
                option.textContent = floor.querySelector('h3').textContent;
                floorSelect.appendChild(option);
            });
            updateDeleteRoomUnitSelect();
            document.getElementById('deleteRoomContent').style.display = 'block';
        }

        function deleteRoom() {
            const roomId = document.getElementById('deleteRoomSelect').value;
            const room = document.getElementById(roomId);
            if (room) {
                const [floorId, unitId, roomNum] = roomId.split('_');
                const unitFullId = `${floorId}_${unitId}`;
                if (!deletedRooms[unitFullId]) {
                    deletedRooms[unitFullId] = [];
                }
                deletedRooms[unitFullId].push(parseInt(roomNum.replace('room', '')));
                deletedRooms[unitFullId].sort((a, b) => a - b);
                room.remove();
                roomCounts[unitFullId]--;
                closeDeleteModal();
            } else {
                alert("Room not found.");
            }
        }

        function updateDeleteRoomUnitSelect() {
            const floorId = document.getElementById('deleteRoomFloorSelect').value;
            const unitSelect = document.getElementById('deleteRoomUnitSelect');
            unitSelect.innerHTML = '';
            document.querySelectorAll(`#${floorId} .unit`).forEach(unit => {
                const option = document.createElement('option');
                option.value = unit.id;
                option.textContent = unit.querySelector('h4').textContent;
                unitSelect.appendChild(option);
            });
            updateDeleteRoomSelect();
        }

        function updateDeleteRoomSelect() {
            const unitId = document.getElementById('deleteRoomUnitSelect').value;
            const roomSelect = document.getElementById('deleteRoomSelect');
            roomSelect.innerHTML = '';
            document.querySelectorAll(`#${unitId} .room-row`).forEach(room => {
                const option = document.createElement('option');
                option.value = room.id;
                option.textContent = `Room ${room.querySelector('input').value}`;
                roomSelect.appendChild(option);
            });
        }

        function hideAllSubModals() {
            const subModals = document.querySelectorAll('.sub-modal-content');
            subModals.forEach(modal => modal.style.display = 'none');
        }

        function closeSubModal(modalId) {
            document.getElementById(modalId).style.display = 'none';
        }

        function addFloor() {
            let newFloorNum;
            if (deletedFloors.length > 0) {
                newFloorNum = deletedFloors.shift();
            } else {
                newFloorNum = maxFloorNumber + 1;
            }
            maxFloorNumber = Math.max(maxFloorNumber, newFloorNum);
            floorCount = maxFloorNumber;

            let floorDiv = `<div class="floor" id="floor${newFloorNum}">
                <h3>Floor ${newFloorNum}</h3>
                <div class="unit" id="floor${newFloorNum}_unit1">
                    <h4>Unit 1</h4>
                    <div class="header-row">
                        <div>Room Number</div>
                        <div>Height</div>
                        <div>Length</div>
                        <div>Depth</div>
                        <div>Window Position</div>
                    </div>
                    <div class="room-row" id="floor${newFloorNum}_unit1_room1">
                        <input type="text" placeholder="Room Number" value="1" />
                        <input type="text" placeholder="Height" />
                        <input type="text" placeholder="Length" />
                        <input type="text" placeholder="Depth" />
                        <input type="text" placeholder="Window Position" />
                    </div>
                </div>
            </div>`;
            document.getElementById('buildingContainer').insertAdjacentHTML('beforeend', floorDiv);
            unitCounts[`floor${newFloorNum}`] = 1;
            roomCounts[`floor${newFloorNum}_unit1`] = 1;
            closeModal();
        }

        function addUnitPrompt() {
            const floorId = document.getElementById('unitFloorSelect').value;
            const unitNumber = document.getElementById('unitNumber').value;
            if (floorId && unitNumber) {
                addUnit(floorId, parseInt(unitNumber));
                closeSubModal('addUnitContent');
            } else {
                alert("Please select a floor and enter a unit number.");
            }
        }

        function addRoomPrompt() {
            const unitId = document.getElementById('roomUnitSelect').value;
            if (unitId) {
                addRoom(unitId);
                closeSubModal('addRoomContent');
            } else {
                alert("Please select a unit.");
            }
        }

        function addUnit(floorId, unitNumber) {
            if (unitNumber <= unitCounts[floorId]) {
                alert("Unit number already exists. Please choose a higher number.");
                return;
            }
            unitCounts[floorId] = Math.max(unitCounts[floorId] || 0, unitNumber);

            let unitDiv = `<div class="unit" id="${floorId}_unit${unitNumber}">
                        <h4>Unit ${unitNumber}</h4>
                        <div class="header-row">
                            <div>Room Number</div>
                            <div>Height</div>
                            <div>Length</div>
                            <div>Depth</div>
                            <div>Window Position</div>
                        </div>
                        <div class="room-row" id="${floorId}_unit${unitNumber}_room1">
                            <input type="text" placeholder="Room Number" value="1" />
                            <input type="text" placeholder="Height" />
                            <input type="text" placeholder="Length" />
                            <input type="text" placeholder="Depth" />
                            <input type="text" placeholder="Window Position" />
                        </div>
                    </div>`;
            document.getElementById(floorId).insertAdjacentHTML('beforeend', unitDiv);
            roomCounts[`${floorId}_unit${unitNumber}`] = 1;
        }

        function addRoom(unitId) {
            let newRoomNum;
            if (deletedRooms[unitId] && deletedRooms[unitId].length > 0) {
                newRoomNum = deletedRooms[unitId].shift();
            } else {
                newRoomNum = roomCounts[unitId] + 1;
            }
            roomCounts[unitId] = Math.max(roomCounts[unitId], newRoomNum);

            let roomDiv = `<div class="room-row" id="${unitId}_room${newRoomNum}">
                        <input type="text" placeholder="Room Number" value="${newRoomNum}" />
                        <input type="text" placeholder="Height" />
                        <input type="text" placeholder="Length" />
                        <input type="text" placeholder="Depth" />
                        <input type="text" placeholder="Window Position" />
                    </div>`;
            document.getElementById(unitId).insertAdjacentHTML('beforeend', roomDiv);
        }

        function deleteFloor() {
            const floorId = document.getElementById('deleteFloorSelect').value;
            const floor = document.getElementById(floorId);
            if (floor) {
                const floorNum = parseInt(floorId.replace('floor', ''));
                deletedFloors.push(floorNum);
                deletedFloors.sort((a, b) => a - b);
                floor.remove();
                delete unitCounts[floorId];
                closeDeleteModal();
            } else {
                alert("Floor not found.");
            }
        }

        function deleteUnit() {
            const unitId = document.getElementById('deleteUnitSelect').value;
            const unit = document.getElementById(unitId);
            if (unit) {
                const [floorId, unitNum] = unitId.split('_');
                if (!deletedUnits[floorId]) {
                    deletedUnits[floorId] = [];
                }
                deletedUnits[floorId].push(parseInt(unitNum.replace('unit', '')));
                deletedUnits[floorId].sort((a, b) => a - b);
                unit.remove();
                delete roomCounts[unitId];
                closeDeleteModal();
            } else {
                alert("Unit not found.");
            }
        }

        function deleteRoom() {
            const roomId = document.getElementById('deleteRoomSelect').value;
            const room = document.getElementById(roomId);
            if (room) {
                const [floorId, unitId, roomNum] = roomId.split('_');
                const fullUnitId = `${floorId}_${unitId}`;
                if (!deletedRooms[fullUnitId]) {
                    deletedRooms[fullUnitId] = [];
                }
                deletedRooms[fullUnitId].push(parseInt(roomNum.replace('room', '')));
                deletedRooms[fullUnitId].sort((a, b) => a - b);
                room.remove();
                closeDeleteModal();
            } else {
                alert("Room not found.");
            }
        }

        function addRoom(unitId) {
            let newRoomNum;
            if (deletedRooms[unitId] && deletedRooms[unitId].length > 0) {
                newRoomNum = deletedRooms[unitId].shift();
            } else {
                newRoomNum = roomCounts[unitId] + 1;
            }
            roomCounts[unitId] = Math.max(roomCounts[unitId], newRoomNum);

            let roomDiv = `<div class="room-row" id="${unitId}_room${newRoomNum}">
                                        <input type="text" placeholder="Room Number" value="${newRoomNum}" />
                                        <input type="text" placeholder="Height" />
                                        <input type="text" placeholder="Length" />
                                        <input type="text" placeholder="Depth" />
                                        <input type="text" placeholder="Window Position" />
                                    </div>`;
            document.getElementById(unitId).insertAdjacentHTML('beforeend', roomDiv);
        }

        // 新增複製相關功能
        function showCopyModal() {
            document.getElementById('copyModal').style.display = 'block';
        }

        function closeCopyModal() {
            document.getElementById('copyModal').style.display = 'none';
            hideAllSubModals();
        }

        function showCopyFloor() {
            hideAllSubModals();
            const select = document.getElementById('sourceFloorSelect');
            select.innerHTML = '';
            document.querySelectorAll('.floor').forEach(floor => {
                const option = document.createElement('option');
                option.value = floor.id;
                option.textContent = floor.querySelector('h3').textContent;
                select.appendChild(option);
            });
            document.getElementById('copyFloorContent').style.display = 'block';
        }

        function showCopyUnit() {
            hideAllSubModals();
            const floorSelect = document.getElementById('sourceUnitFloorSelect');
            const targetFloorSelect = document.getElementById('targetUnitFloorSelect');
            floorSelect.innerHTML = '';
            targetFloorSelect.innerHTML = '';

            document.querySelectorAll('.floor').forEach(floor => {
                const option1 = document.createElement('option');
                const option2 = document.createElement('option');
                option1.value = option2.value = floor.id;
                option1.textContent = option2.textContent = floor.querySelector('h3').textContent;
                floorSelect.appendChild(option1);
                targetFloorSelect.appendChild(option2);
            });

            updateSourceUnitSelect();
            document.getElementById('copyUnitContent').style.display = 'block';
        }

        function showCopyRoom() {
            hideAllSubModals();
            const sourceFloorSelect = document.getElementById('sourceRoomFloorSelect');
            const targetFloorSelect = document.getElementById('targetRoomFloorSelect');
            sourceFloorSelect.innerHTML = '';
            targetFloorSelect.innerHTML = '';

            document.querySelectorAll('.floor').forEach(floor => {
                const option1 = document.createElement('option');
                const option2 = document.createElement('option');
                option1.value = option2.value = floor.id;
                option1.textContent = option2.textContent = floor.querySelector('h3').textContent;
                sourceFloorSelect.appendChild(option1);
                targetFloorSelect.appendChild(option2);
            });

            updateSourceRoomUnitSelect();
            updateTargetRoomUnitSelect();
            document.getElementById('copyRoomContent').style.display = 'block';
        }

        function updateSourceUnitSelect() {
            const floorId = document.getElementById('sourceUnitFloorSelect').value;
            const unitSelect = document.getElementById('sourceUnitSelect');
            unitSelect.innerHTML = '';
            document.querySelectorAll(`#${floorId} .unit`).forEach(unit => {
                const option = document.createElement('option');
                option.value = unit.id;
                option.textContent = unit.querySelector('h4').textContent;
                unitSelect.appendChild(option);
            });
        }

        function updateSourceRoomUnitSelect() {
            const floorId = document.getElementById('sourceRoomFloorSelect').value;
            const unitSelect = document.getElementById('sourceRoomUnitSelect');
            unitSelect.innerHTML = '';
            document.querySelectorAll(`#${floorId} .unit`).forEach(unit => {
                const option = document.createElement('option');
                option.value = unit.id;
                option.textContent = unit.querySelector('h4').textContent;
                unitSelect.appendChild(option);
            });
            updateSourceRoomSelect();
        }

        function updateTargetRoomUnitSelect() {
            const floorId = document.getElementById('targetRoomFloorSelect').value;
            const unitSelect = document.getElementById('targetRoomUnitSelect');
            unitSelect.innerHTML = '';
            document.querySelectorAll(`#${floorId} .unit`).forEach(unit => {
                const option = document.createElement('option');
                option.value = unit.id;
                option.textContent = unit.querySelector('h4').textContent;
                unitSelect.appendChild(option);
            });
        }

        function updateSourceRoomSelect() {
            const unitId = document.getElementById('sourceRoomUnitSelect').value;
            const roomSelect = document.getElementById('sourceRoomSelect');
            roomSelect.innerHTML = '';
            document.querySelectorAll(`#${unitId} .room-row`).forEach(room => {
                const option = document.createElement('option');
                option.value = room.id;
                option.textContent = `Room ${room.querySelector('input').value}`;
                roomSelect.appendChild(option);
            });
        }

        function copyFloor() {
            const sourceFloorId = document.getElementById('sourceFloorSelect').value;
            const targetFloorNum = parseInt(document.getElementById('targetFloorNumber').value);

            if (!sourceFloorId || !targetFloorNum) {
                alert("Please select source floor and target floor number.");
                return;
            }

            const sourceFloor = document.getElementById(sourceFloorId);
            const newFloorId = `floor${targetFloorNum}`;

            // 檢查目標樓層是否已存在
            if (document.getElementById(newFloorId)) {
                alert("Target floor already exists. Please choose a different number.");
                return;
            }

            // 創建新樓層並複製內容
            const newFloor = sourceFloor.cloneNode(true);
            newFloor.id = newFloorId;
            newFloor.querySelector('h3').textContent = `Floor ${targetFloorNum}`;

            // 更新單元和房間的 ID
            newFloor.querySelectorAll('.unit').forEach((unit, unitIndex) => {
                const originalUnitNum = unit.id.split('_unit')[1];
                const newUnitId = `${newFloorId}_unit${originalUnitNum}`;
                unit.id = newUnitId;
                unitCounts[newFloorId] = Math.max(unitCounts[newFloorId] || 0, parseInt(originalUnitNum));

                unit.querySelectorAll('.room-row').forEach((room, roomIndex) => {
                    const originalRoomNum = room.id.split('_room')[1];
                    room.id = `${newUnitId}_room${originalRoomNum}`;
                    if (!roomCounts[newUnitId]) {
                        roomCounts[newUnitId] = 0;
                    }
                    roomCounts[newUnitId] = Math.max(roomCounts[newUnitId], parseInt(originalRoomNum));
                });
            });

            document.getElementById('buildingContainer').appendChild(newFloor);
            maxFloorNumber = Math.max(maxFloorNumber, targetFloorNum);
            floorCount = maxFloorNumber;
            closeCopyModal();
        }

        function copyUnit() {
            const sourceUnitId = document.getElementById('sourceUnitSelect').value;
            const targetFloorId = document.getElementById('targetUnitFloorSelect').value;
            const targetUnitNum = parseInt(document.getElementById('targetUnitNumber').value);

            if (!sourceUnitId || !targetFloorId || !targetUnitNum) {
                alert("Please fill in all required fields.");
                return;
            }

            const targetUnitId = `${targetFloorId}_unit${targetUnitNum}`;

            // 檢查目標單元是否已存在
            if (document.getElementById(targetUnitId)) {
                alert("Target unit already exists. Please choose a different number.");
                return;
            }

            const sourceUnit = document.getElementById(sourceUnitId);
            const newUnit = sourceUnit.cloneNode(true);
            newUnit.id = targetUnitId;
            newUnit.querySelector('h4').textContent = `Unit ${targetUnitNum}`;

            // 更新房間的 ID
            newUnit.querySelectorAll('.room-row').forEach((room) => {
                const originalRoomNum = room.id.split('_room')[1];
                room.id = `${targetUnitId}_room${originalRoomNum}`;
            });

            // 更新計數器
            unitCounts[targetFloorId] = Math.max(unitCounts[targetFloorId] || 0, targetUnitNum);
            roomCounts[targetUnitId] = sourceUnit.querySelectorAll('.room-row').length;

            document.getElementById(targetFloorId).appendChild(newUnit);
            closeCopyModal();
        }

        function copyRoom() {
            const sourceRoomId = document.getElementById('sourceRoomSelect').value;
            const targetUnitId = document.getElementById('targetRoomUnitSelect').value;

            if (!sourceRoomId || !targetUnitId) {
                alert("Please fill in all required fields.");
                return;
            }

            // 获取源房間
            const sourceRoom = document.getElementById(sourceRoomId);
            if (!sourceRoom) {
                alert("Source room not found.");
                return;
            }

            // 獲取目標單元中的房間數量，用於生成新房間號碼
            let newRoomNum;
            if (deletedRooms[targetUnitId] && deletedRooms[targetUnitId].length > 0) {
                newRoomNum = deletedRooms[targetUnitId].shift();
            } else {
                newRoomNum = (roomCounts[targetUnitId] || 0) + 1;
            }

            // 創建新房間
            const newRoom = sourceRoom.cloneNode(true);
            newRoom.id = `${targetUnitId}_room${newRoomNum}`;

            // 複製所有輸入值
            const sourceInputs = sourceRoom.querySelectorAll('input');
            const newInputs = newRoom.querySelectorAll('input');

            // 更新房間號碼，保持其他值不變
            newInputs[0].value = newRoomNum;
            for (let i = 1; i < sourceInputs.length; i++) {
                newInputs[i].value = sourceInputs[i].value;
            }

            // 將新房間添加到目標單元
            document.getElementById(targetUnitId).appendChild(newRoom);

            // 更新房間計數
            roomCounts[targetUnitId] = Math.max(roomCounts[targetUnitId] || 0, newRoomNum);

            closeCopyModal();
        }

        function save() {
            const buildingData = {
                floors: {},
                unitCounts: unitCounts,
                roomCounts: roomCounts,
                deletedFloors: deletedFloors,
                deletedUnits: deletedUnits,
                deletedRooms: deletedRooms
            };

            document.querySelectorAll('.floor').forEach(floor => {
                const floorId = floor.id;
                buildingData.floors[floorId] = {
                    units: {}
                };

                floor.querySelectorAll('.unit').forEach(unit => {
                    const unitId = unit.id;
                    buildingData.floors[floorId].units[unitId] = {
                        rooms: {}
                    };

                    unit.querySelectorAll('.room-row').forEach(room => {
                        const roomId = room.id;
                        const inputs = room.querySelectorAll('input');
                        buildingData.floors[floorId].units[unitId].rooms[roomId] = {
                            roomNumber: inputs[0].value,
                            height: inputs[1].value,
                            length: inputs[2].value,
                            depth: inputs[3].value,
                            windowPosition: inputs[4].value
                        };
                    });
                });
            });

            // 將 buildingData 轉換為 Excel 格式
            const wb = XLSX.utils.book_new();  // 創建一個新的工作簿

            Object.keys(buildingData.floors).forEach(floorId => {
                const ws_data = [['Unit', 'Room Number', 'Height', 'Length', 'Depth', 'Window Position']];
                const floor = buildingData.floors[floorId];

                Object.keys(floor.units).forEach(unitId => {
                    const unit = floor.units[unitId];
                    Object.keys(unit.rooms).forEach(roomId => {
                        const room = unit.rooms[roomId];
                        ws_data.push([unitId, room.roomNumber, room.height, room.length, room.depth, room.windowPosition]);
                    });
                });

                // 創建新的工作表
                const ws = XLSX.utils.aoa_to_sheet(ws_data);
                XLSX.utils.book_append_sheet(wb, ws, floorId);  // 將工作表添加到工作簿中
            });

            // 觸發 Excel 檔案下載
            XLSX.writeFile(wb, 'buildingData.xlsx');

            alert('Data saved as Excel file!');
        }

        // 用於初始化時加載保存的數據
        function loadSavedData() {
            // 每次載入時清除本地儲存的資料，保證重新開始
            localStorage.removeItem('buildingData');

            // 建立預設的樓層、單元和房間
            const container = document.getElementById('buildingContainer');
            container.innerHTML = ''; // 清除容器內容

            // 創建預設的 floor1, unit1 和 room1
            const floorDiv = createFloorElement('floor1');
            const unitDiv = createUnitElement('floor1_unit1');
            const roomDiv = createRoomElement('floor1_unit1_room1', {
                roomNumber: '1',
                height: '',
                length: '',
                depth: '',
                windowPosition: ''
            });

            // 將它們添加到 DOM
            unitDiv.appendChild(roomDiv);
            floorDiv.appendChild(unitDiv);
            container.appendChild(floorDiv);
        }


        function createFloorElement(floorId) {
            const floorNum = floorId.replace('floor', '');
            const floorDiv = document.createElement('div');
            floorDiv.className = 'floor';
            floorDiv.id = floorId;
            floorDiv.innerHTML = `<h3>Floor ${floorNum}</h3>`;
            return floorDiv;
        }

        function createUnitElement(unitId) {
            const unitNum = unitId.split('_unit')[1];
            const unitDiv = document.createElement('div');
            unitDiv.className = 'unit';
            unitDiv.id = unitId;
            unitDiv.innerHTML = `
                        <h4>Unit ${unitNum}</h4>
                        <div class="header-row">
                            <div>Room Number</div>
                            <div>Height</div>
                            <div>Length</div>
                            <div>Depth</div>
                            <div>Window Position</div>
                        </div>
                    `;
            return unitDiv;
        }

        function createRoomElement(roomId, roomData) {
            const roomDiv = document.createElement('div');
            roomDiv.className = 'room-row';
            roomDiv.id = roomId;
            roomDiv.innerHTML = `
                        <input type="text" placeholder="Room Number" value="${roomData.roomNumber}" />
                        <input type="text" placeholder="Height" value="${roomData.height}" />
                        <input type="text" placeholder="Length" value="${roomData.length}" />
                        <input type="text" placeholder="Depth" value="${roomData.depth}" />
                        <input type="text" placeholder="Window Position" value="${roomData.windowPosition}" />
                    `;
            return roomDiv;
        }

        // 頁面加載時初始化數據
        document.addEventListener('DOMContentLoaded', function () {
            loadSavedData();
        });

        function calculate() {
            let totalHeight = 0;
            let totalLength = 0;
            let totalDepth = 0;

            // 遍歷每個樓層
            document.querySelectorAll('.floor').forEach(floor => {
                // 遍歷每個單元
                floor.querySelectorAll('.unit').forEach(unit => {
                    // 遍歷每個房間
                    unit.querySelectorAll('.room-row').forEach(room => {
                        const height = parseFloat(room.querySelector('input[placeholder="Height"]').value);
                        const length = parseFloat(room.querySelector('input[placeholder="Length"]').value);
                        const depth = parseFloat(room.querySelector('input[placeholder="Depth"]').value);

                        // 累加總和
                        totalHeight += isNaN(height) ? 0 : height;
                        totalLength += isNaN(length) ? 0 : length;
                        totalDepth += isNaN(depth) ? 0 : depth;
                    });
                });
            });

            // 顯示結果
            const result = `總高度: ${totalHeight}\n總長度: ${totalLength}\n總深度: ${totalDepth}`;
            showResultModal(result);
        }

        function showResultModal(result) {
            const modal = document.createElement('div');
            modal.style.position = 'fixed';
            modal.style.top = '50%';
            modal.style.left = '50%';
            modal.style.transform = 'translate(-50%, -50%)';
            modal.style.backgroundColor = 'white';
            modal.style.padding = '20px';
            modal.style.boxShadow = '0 0 10px rgba(0, 0, 0, 0.5)';
            modal.style.zIndex = '1000';

            // 設置視窗的寬度和高度
            modal.style.width = '400px'; // 您可以根據需要調整這裡的值
            modal.style.height = 'auto';  // 高度自動根據內容調整
            modal.style.overflowY = 'auto'; // 若內容過多可滾動

            // 增加圓弧
            modal.style.borderRadius = '10px'; // 調整這裡的值來改變圓弧的大小

            const modalContent = document.createElement('div'); // 使用 div 來包裹內容
            modalContent.style.textAlign = 'center'; // 文字置中
            modalContent.style.marginBottom = '10px'; // 加入一些底部邊距
            modalContent.style.fontSize = '22px'; // 設置字體大小，您可以根據需求調整這裡的值
            modalContent.textContent = result; // 將計算結果設置為內容
            modal.appendChild(modalContent);

            const closeButtonContainer = document.createElement('div');
            closeButtonContainer.style.display = 'flex'; // 使用 flex 排版
            closeButtonContainer.style.justifyContent = 'center'; // 使按鈕置中對齊

            const closeButton = document.createElement('button');
            closeButton.textContent = '關閉';
            closeButton.onclick = () => {
                document.body.removeChild(modal);
            };

            closeButtonContainer.appendChild(closeButton);
            modal.appendChild(closeButtonContainer);

            document.body.appendChild(modal);
        }


    </script>
    
    <!-- 先加載翻譯文件 -->
    <script src="GBS_js/translations.js"></script>
    <!-- 後加載 i18n 類 -->
    <script src="GBS_js/i18n.js"></script>
    
    <script>
        // 為了同步 navbar 和頁面的語言切換
        window.addEventListener('storage', function(e) {
            if (e.key === 'language') {
                window.location.reload();
            }
        });

        // 當頁面加載完成時，更新所有翻譯
        document.addEventListener('DOMContentLoaded', function() {
            updatePageTranslations();
        });

        function updatePageTranslations() {
            const elements = document.querySelectorAll('[data-i18n]');
            const currentLang = localStorage.getItem('language') || 'zh-TW';
            
            elements.forEach(element => {
                const key = element.getAttribute('data-i18n');
                if (translations[currentLang][key]) {
                    element.textContent = translations[currentLang][key];
                }
            });

            // 更新 placeholder 翻譯
            const placeholders = document.querySelectorAll('[data-i18n-placeholder]');
            placeholders.forEach(element => {
                const key = element.getAttribute('data-i18n-placeholder');
                if (translations[currentLang][key]) {
                    element.placeholder = translations[currentLang][key];
                }
            });
        }
    </script>
</body>
</html>