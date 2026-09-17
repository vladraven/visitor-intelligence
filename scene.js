import * as THREE from 'three';
import { OrbitControls } from 'three/examples/jsm/controls/OrbitControls.js';
import { hitboxes, openingsData, isMetric, openingDefs } from './state.js';
import { updateBuilding } from './builder.js';
import { populateOpeningsUI } from './ui.js';

export const scene = new THREE.Scene();
export const mainGroup = new THREE.Group();

export let camera, renderer, controls;
export let grassMesh;

const raycaster = new THREE.Raycaster();
const mouse = new THREE.Vector2();

let draggedOpening = null;    
let currentSelectedOpening = null;
let dragOffsetX = 0;
let dragOffsetY = 0;

const ghostMaterial = new THREE.MeshBasicMaterial({
    color: 0x3b82f6,
    transparent: true,
    opacity: 0.45,
    side: THREE.DoubleSide,
    depthTest: false
});

const dragGhostMesh = new THREE.Mesh(new THREE.PlaneGeometry(1, 1), ghostMaterial);
dragGhostMesh.visible = false;
dragGhostMesh.renderOrder = 999;
scene.add(dragGhostMesh);

// Загрузка текстуры травы
const textureLoader = new THREE.TextureLoader();
textureLoader.setCrossOrigin('anonymous');
const grassTex = textureLoader.load('https://cdn.jsdelivr.net/gh/mrdoob/three.js@r148/examples/textures/terrain/grasslight-big.jpg');
grassTex.wrapS = THREE.RepeatWrapping;
grassTex.wrapT = THREE.RepeatWrapping;
// Уменьшаем количество повторений, чтобы текстура была крупнее (микрорельеф)
grassTex.repeat.set(160, 160); 
grassTex.anisotropy = 16; 

// Материал травы с поддержкой вершинных цветов и рельефа (Bump)
const grassMeshMat = new THREE.MeshStandardMaterial({ 
    map: grassTex, 
    bumpMap: grassTex,     // Используем ту же текстуру для объема
    bumpScale: 0.15,       // Высота травинок
    vertexColors: true,    // Включаем макро-цвета (пятна на ландшафте)
    roughness: 0.95,
    metalness: 0.0
});

// Skybox куб текстур из оригинальных коммитов
const skyPath = 'https://cdn.jsdelivr.net/gh/mrdoob/three.js@r148/examples/textures/cube/skyboxsun25deg/';
const cubeTextureLoader = new THREE.CubeTextureLoader();
cubeTextureLoader.setCrossOrigin('anonymous');
const skyboxTexture = cubeTextureLoader.load([
    skyPath + 'px.jpg',
    skyPath + 'nx.jpg',
    skyPath + 'py.jpg',
    skyPath + 'ny.jpg',
    skyPath + 'pz.jpg',
    skyPath + 'nz.jpg'
]);

export function initScene(container) {
    scene.background = skyboxTexture;
    scene.fog = new THREE.FogExp2(0xdce7f3, 0.0006);

    camera = new THREE.PerspectiveCamera(45, container.clientWidth / container.clientHeight, 0.1, 5000);
    camera.position.set(70, 15, 70); 

    renderer = new THREE.WebGLRenderer({ antialias: true, preserveDrawingBuffer: true, powerPreference: "high-performance" });
    renderer.setSize(container.clientWidth, container.clientHeight);
    renderer.setPixelRatio(window.devicePixelRatio);
    
    renderer.shadowMap.enabled = true;
    renderer.shadowMap.type = THREE.PCFSoftShadowMap;

    container.appendChild(renderer.domElement);

    controls = new OrbitControls(camera, renderer.domElement);
    controls.enableDamping = true;
    controls.maxPolarAngle = Math.PI / 2 - 0.05;
    controls.zoomSpeed = 0.75;
    controls.maxDistance = 250;
    controls.minDistance = 2;
    controls.autoRotate = true;
    controls.autoRotateSpeed = 0.2;

    // Much brighter environment lighting overall
    const hemiLight = new THREE.HemisphereLight(0xffffff, 0x5a5a6a, .5);
    hemiLight.position.set(0, 200, 0);
    scene.add(hemiLight);

    const ambientLight = new THREE.AmbientLight(0xffffff, .5);
    const ambientLight = new THREE.AmbientLight(0xffffff, .5);
    scene.add(ambientLight);

    const sun = new THREE.DirectionalLight(0xfffaea, 2.8);
    sun.position.set(150, 250, 120);
    sun.castShadow = true;

    sun.shadow.bias = -0.001;        
    sun.shadow.normalBias = 0.05;     
    
    sun.shadow.radius = 2.5;            
    sun.shadow.mapSize.width = 4096;  
    sun.shadow.mapSize.height = 4096;

    sun.shadow.camera.near = 10;
    sun.shadow.camera.far = 600;
    sun.shadow.camera.top = 80;
    sun.shadow.camera.bottom = -20;
    sun.shadow.camera.left = -180;
    sun.shadow.camera.right = 180;

    scene.add(sun);

    createHillyTerrain();
    scene.add(mainGroup);

    window.addEventListener('resize', () => {
        camera.aspect = container.clientWidth / container.clientHeight;
        camera.updateProjectionMatrix();
        renderer.setSize(container.clientWidth, container.clientHeight);
    });

    setupDragAndDrop(container);
    setupPopupHandlers();

    return { scene, camera, renderer, controls, mainGroup };
}

function createHillyTerrain() {
    const worldSize = 3000;
    const segments = 128;

    const terrainGeo = new THREE.PlaneGeometry(worldSize, worldSize, segments, segments);
    const position = terrainGeo.attributes.position;
    
    // Массив для хранения цветов вершин (R, G, B для каждой вершины)
    const colors = new Float32Array(position.count * 3);
    const colorObj = new THREE.Color();

    for (let i = 0; i < position.count; i++) {
        const x = position.getX(i);
        const y = position.getY(i);
        const distFromCenter = Math.sqrt(x * x + y * y);

        // 1. Формирование холмов
        if (distFromCenter > 90) {
            const factor = Math.min(1.0, (distFromCenter - 90) / 350);
            const z = (Math.sin(x * 0.012) * Math.cos(y * 0.012) * 3.0 + Math.sin(x * 0.003) * 5.0) * factor;
            position.setZ(i, z);
        } else {
            position.setZ(i, 0);
        }

        // 2. Добавление макро-цвета для разбивки тайлинга текстуры
        // Используем комбинацию синусоид для создания случайных природных пятен (noise)
        const noise = (Math.sin(x * 0.005) + Math.cos(y * 0.006) + Math.sin((x + y) * 0.002)) / 3;
        
        // Яркий, насыщенный зелёный газон (FIX 2: было слишком серо/тускло: sat ~0.01-0.02, light ~0.25-0.35)
        const hue = 0.32 + (noise * 0.025); // Чистый зелёный тон
        const saturation = 0.25 + (noise * 0.12); // Насыщенные, но естественные пятна травы
        const lightness = 0.1 + (noise * 0.02); // Заметно светлее, без сероватого налёта

        colorObj.setHSL(hue, saturation, lightness);
        
        colors[i * 3] = colorObj.r;
        colors[i * 3 + 1] = colorObj.g;
        colors[i * 3 + 2] = colorObj.b;
    }

    terrainGeo.computeVertexNormals();
    // Применяем цвета к геометрии
    terrainGeo.setAttribute('color', new THREE.BufferAttribute(colors, 3));

    grassMesh = new THREE.Mesh(terrainGeo, grassMeshMat);
    grassMesh.rotation.x = -Math.PI / 2;
    grassMesh.position.y = -0.02; 
    grassMesh.receiveShadow = true;
    scene.add(grassMesh);
}

function resolveStrictCollisions(side, currentOpId, targetX, targetY, currW, currH) {
    const wallOps = openingsData[side] || [];
    let clampedX = targetX;

    wallOps.forEach(otherOp => {
        if (otherOp.id === currentOpId) return;

        const otherDef = openingDefs[otherOp.type] || { w: 1, h: 1 };
        const otherW = otherOp.w || otherDef.w;
        const otherH = otherOp.h || otherDef.h;
        const otherY = (otherOp.type === 'Window') ? (otherOp.yOff !== undefined ? otherOp.yOff : 1.0) : 0;

        const currCenterY = targetY + currH / 2;
        const otherCenterY = otherY + otherH / 2;

        const overlapY = Math.abs(currCenterY - otherCenterY) < (currH / 2 + otherH / 2 - 0.01);

        if (overlapY) {
            const minAllowedX = otherOp.x - (otherW / 2 + currW / 2);
            const maxAllowedX = otherOp.x + (otherW / 2 + currW / 2);

            if (clampedX > minAllowedX && clampedX < maxAllowedX) {
                const distToLeft = Math.abs(clampedX - minAllowedX);
                const distToRight = Math.abs(clampedX - maxAllowedX);

                if (distToLeft < distToRight) {
                    clampedX = minAllowedX;
                } else {
                    clampedX = maxAllowedX;
                }
            }
        }
    });

    return clampedX;
}

// FIX 7: Shared raw wall-plane raycast used by both pointerdown (to capture the
// click offset) and pointermove (to re-derive the hit each frame). Returns the
// intersection point in wall-local coordinates (X along the wall, raw world Y),
// or null if the current raycaster ray doesn't hit the opening's wall plane.
function getRawWallHit(opening) {
    const side = opening.side;
    if (!opening.meshGroup || !opening.meshGroup.parent) return null;

    const planeNormal = new THREE.Vector3();
    if (side === 'F') planeNormal.set(0, 0, 1);
    else if (side === 'B') planeNormal.set(0, 0, -1);
    else if (side === 'L') planeNormal.set(-1, 0, 0);
    else if (side === 'R') planeNormal.set(1, 0, 0);

    const plane = new THREE.Plane();
    plane.setFromNormalAndCoplanarPoint(planeNormal, opening.meshGroup.parent.position);

    const intersection = new THREE.Vector3();
    if (!raycaster.ray.intersectPlane(plane, intersection)) return null;

    const localX = (side === 'F' || side === 'B') ? intersection.x : intersection.z;
    return { localX, worldY: intersection.y };
}

function setupDragAndDrop(container) {
    const stopAutoRotation = () => {
        if (controls && controls.autoRotate) {
            controls.autoRotate = false;
        }
    };

    container.addEventListener('dblclick', (e) => {
        stopAutoRotation();

        const rect = renderer.domElement.getBoundingClientRect();
        mouse.x = ((e.clientX - rect.left) / rect.width) * 2 - 1;
        mouse.y = -((e.clientY - rect.top) / rect.height) * 2 + 1;

        raycaster.setFromCamera(mouse, camera);
        const intersects = raycaster.intersectObjects(hitboxes, true);

        if (intersects.length > 0) {
            let hitObj = intersects[0].object;
            while (hitObj && (!hitObj.userData || !hitObj.userData.isOpening) && hitObj.parent) {
                hitObj = hitObj.parent;
            }

            if (hitObj && hitObj.userData && hitObj.userData.isOpening) {
                openOpeningPopup(hitObj.userData, e.clientX, e.clientY);
            }
        }
    });

    container.addEventListener('pointerdown', (e) => {
        stopAutoRotation();

        const rect = renderer.domElement.getBoundingClientRect();
        mouse.x = ((e.clientX - rect.left) / rect.width) * 2 - 1;
        mouse.y = -((e.clientY - rect.top) / rect.height) * 2 + 1;

        raycaster.setFromCamera(mouse, camera);
        const intersects = raycaster.intersectObjects(hitboxes, true);

        if (intersects.length > 0) {
            for (let i = 0; i < intersects.length; i++) {
                let hitObj = intersects[i].object;
                while (hitObj && (!hitObj.userData || !hitObj.userData.isOpening) && hitObj.parent) {
                    hitObj = hitObj.parent;
                }

                if (hitObj && hitObj.userData && hitObj.userData.isOpening) {
                    draggedOpening = hitObj.userData;
                    controls.enabled = false;

                    const def = openingDefs[draggedOpening.opData.type];
                    const opW = draggedOpening.opData.w || (def ? def.w : 1.0);
                    const opH = draggedOpening.opData.h || (def ? def.h : 1.0);

                    // FIX 7: capture the offset between the opening's current
                    // center and the raw raycast hit at the moment of click, so
                    // dragging preserves where the user actually grabbed the
                    // opening instead of snapping its center to the cursor.
                    const initHit = getRawWallHit(draggedOpening);
                    if (initHit) {
                        dragOffsetX = draggedOpening.opData.x - initHit.localX;
                        const currentYOff = (draggedOpening.opData.type === 'Window')
                            ? (draggedOpening.opData.yOff !== undefined ? draggedOpening.opData.yOff : 1.0)
                            : 0;
                        const hitYOff = Math.max(0, initHit.worldY - opH / 2);
                        dragOffsetY = currentYOff - hitYOff;
                    } else {
                        dragOffsetX = 0;
                        dragOffsetY = 0;
                    }

                    dragGhostMesh.geometry.dispose();
                    dragGhostMesh.geometry = new THREE.PlaneGeometry(opW, opH);

                    if (draggedOpening.meshGroup && draggedOpening.meshGroup.parent) {
                        draggedOpening.meshGroup.parent.add(dragGhostMesh);
                        dragGhostMesh.visible = true;
                    }
                    break;
                }
            }
        }
    });

    container.addEventListener('pointermove', (e) => {
        if (!draggedOpening) return;

        stopAutoRotation();

        const rect = renderer.domElement.getBoundingClientRect();
        mouse.x = ((e.clientX - rect.left) / rect.width) * 2 - 1;
        mouse.y = -((e.clientY - rect.top) / rect.height) * 2 + 1;

        raycaster.setFromCamera(mouse, camera);

        const side = draggedOpening.side;
        const wallLength = draggedOpening.wallLength;
        const def = openingDefs[draggedOpening.opData.type];
        const opW = draggedOpening.opData.w || (def ? def.w : 1.0);
        const opH = draggedOpening.opData.h || (def ? def.h : 1.0);
        const halfOpW = opW / 2;

        const plane = new THREE.Plane();
        const planeNormal = new THREE.Vector3();

        if (side === 'F') planeNormal.set(0, 0, 1);
        else if (side === 'B') planeNormal.set(0, 0, -1);
        else if (side === 'L') planeNormal.set(-1, 0, 0);
        else if (side === 'R') planeNormal.set(1, 0, 0);

        if (!draggedOpening.meshGroup || !draggedOpening.meshGroup.parent) return;

        plane.setFromNormalAndCoplanarPoint(planeNormal, draggedOpening.meshGroup.parent.position);

        const intersection = new THREE.Vector3();
        if (raycaster.ray.intersectPlane(plane, intersection)) {
            let localX = (side === 'F' || side === 'B') ? intersection.x : intersection.z;

            let localY = 0;
            if (draggedOpening.opData.type === 'Window') {
                localY = Math.max(0, intersection.y - opH / 2);
            } else {
                localY = 0;
            }

            // FIX 7: re-apply the offset captured on pointerdown so the opening
            // keeps its position relative to the initial click instead of
            // jumping so its center snaps to the raw raycast hit.
            localX = localX + dragOffsetX;
            if (draggedOpening.opData.type === 'Window') {
                localY = Math.max(0, localY + dragOffsetY);
            }

            localX = resolveStrictCollisions(side, draggedOpening.opData.id, localX, localY, opW, opH);

            const minBound = -wallLength / 2 + halfOpW;
            const maxBound = wallLength / 2 - halfOpW;
            localX = Math.max(minBound, Math.min(maxBound, localX));

            draggedOpening.opData.x = localX;
            draggedOpening.opData.yOff = localY;

            draggedOpening.meshGroup.position.set(localX, localY + opH / 2, 0);
            dragGhostMesh.position.set(localX, localY + opH / 2, 0.05);
        }
    });

    container.addEventListener('pointerup', () => {
        if (draggedOpening) {
            dragGhostMesh.visible = false;
            if (dragGhostMesh.parent) {
                dragGhostMesh.parent.remove(dragGhostMesh);
            }
            draggedOpening = null;
            dragOffsetX = 0;
            dragOffsetY = 0;
            controls.enabled = true;
            updateBuilding();
        }
    });
}

function openOpeningPopup(openingUserData, screenX, screenY) {
    currentSelectedOpening = openingUserData;
    const op = openingUserData.opData;
    const popup = document.getElementById('openingPopup');
    if (!popup) return;

    document.querySelectorAll('.popup-unit').forEach(el => el.innerText = isMetric ? 'm' : 'ft');
    document.getElementById('popupTitle').innerText = `Edit ${op.type}`;

    const mult = isMetric ? 1 : 3.28084;
    document.getElementById('popupOpWidth').value = (op.w * mult).toFixed(1);
    document.getElementById('popupOpHeight').value = (op.h * mult).toFixed(1);
    document.getElementById('popupOpOffset').value = (op.x * mult).toFixed(1);

    const yContainer = document.getElementById('popupYOffContainer');
    if (op.type === 'Window') {
        if (yContainer) yContainer.style.display = 'block';
        document.getElementById('popupOpYOff').value = ((op.yOff || 1.0) * mult).toFixed(1);
    } else {
        if (yContainer) yContainer.style.display = 'none';
    }

    popup.style.left = `${Math.min(screenX + 10, window.innerWidth - 280)}px`;
    popup.style.top = `${Math.min(screenY + 10, window.innerHeight - 250)}px`;
    popup.style.display = 'block';
}

function setupPopupHandlers() {
    const popup = document.getElementById('openingPopup');
    if (!popup) return;

    document.getElementById('btnPopupCancel')?.addEventListener('click', () => {
        popup.style.display = 'none';
        currentSelectedOpening = null;
    });

    document.getElementById('btnPopupDelete')?.addEventListener('click', () => {
        if (!currentSelectedOpening) return;
        const { side, opData } = currentSelectedOpening;
        openingsData[side] = openingsData[side].filter(o => String(o.id) !== String(opData.id));
        popup.style.display = 'none';
        currentSelectedOpening = null;
        populateOpeningsUI(updateBuilding);
        updateBuilding();
    });

    document.getElementById('btnPopupUpdate')?.addEventListener('click', () => {
        if (!currentSelectedOpening) return;
        const { side, opData, wallLength } = currentSelectedOpening;

        let wVal = parseFloat(document.getElementById('popupOpWidth').value);
        let hVal = parseFloat(document.getElementById('popupOpHeight').value);
        let xVal = parseFloat(document.getElementById('popupOpOffset').value);
        let yVal = parseFloat(document.getElementById('popupOpYOff').value);

        const mult = isMetric ? 1 : 0.3048;
        const internalW = wVal * mult;
        const internalH = hVal * mult;
        let internalX = xVal * mult;
        let internalY = (opData.type === 'Window') ? yVal * mult : 0;

        internalX = resolveStrictCollisions(side, opData.id, internalX, internalY, internalW, internalH);
        const halfOpW = internalW / 2;
        internalX = Math.max(-wallLength / 2 + halfOpW, Math.min(wallLength / 2 - halfOpW, internalX));

        opData.w = internalW;
        opData.h = internalH;
        opData.x = internalX;
        opData.yOff = internalY;

        popup.style.display = 'none';
        currentSelectedOpening = null;
        populateOpeningsUI(updateBuilding);
        updateBuilding();
    });
}

export function animate() {
    requestAnimationFrame(animate);
    if (controls) controls.update();
    if (renderer && scene && camera) renderer.render(scene, camera);
}