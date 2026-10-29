// public/welcome/welcome.js
(function () {
    'use strict';

    var reduce = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    var canvas = document.getElementById('webglCanvas');
    var container = document.getElementById('stageContainer');
    var glow = document.getElementById('glow');

    if (!canvas || !container || typeof THREE === 'undefined') {
        return;
    }

    /* -------------------------------------------------------------
       1. SCENE, CAMERA & WEBGL RENDERER
       ------------------------------------------------------------- */
    var scene = new THREE.Scene();

    var renderer = new THREE.WebGLRenderer({
        canvas: canvas,
        alpha: true,
        antialias: true,
        powerPreference: 'high-performance'
    });
    renderer.setPixelRatio(Math.min(window.devicePixelRatio, 2));
    renderer.shadowMap.enabled = true;
    renderer.shadowMap.type = THREE.PCFSoftShadowMap;
    renderer.toneMapping = THREE.ACESFilmicToneMapping;
    renderer.toneMappingExposure = 0.95;

    // Skyscraper camera (low position looking up)
    var camera = new THREE.PerspectiveCamera(36, 1, 0.1, 100);
    camera.position.set(2.4, -2.1, 8.8);
    camera.lookAt(1.4, 0.4, 0.0);

    /* -------------------------------------------------------------
       2. STUDIO LIGHTING RIG (Glare dimmed, ambient & rim preserved)
       ------------------------------------------------------------- */
    var ambientLight = new THREE.AmbientLight(0xffffff, 0.35);
    scene.add(ambientLight);

    // Key Light: Softer intensity and grazing angle to remove the harsh glare blob
    var keyLight = new THREE.DirectionalLight(0xffffff, 0.72);
    keyLight.position.set(-6, 7, 4.2);
    keyLight.castShadow = true;
    keyLight.shadow.mapSize.width = 2048;
    keyLight.shadow.mapSize.height = 2048;
    keyLight.shadow.camera.near = 0.5;
    keyLight.shadow.camera.far = 25;
    keyLight.shadow.bias = -0.0008;
    scene.add(keyLight);

    // Rim Light (preserves the crisp side edge highlight)
    var rimLight = new THREE.DirectionalLight(0xffffff, 1.3);
    rimLight.position.set(-8, -1, 4);
    scene.add(rimLight);

    // Dynamic Roving Sweep Light circling the card
    var shineSweepLight = new THREE.PointLight(0xffffff, 0.65, 14);
    shineSweepLight.position.set(1.5, 2.0, 4.0);
    scene.add(shineSweepLight);

    /* -------------------------------------------------------------
       3. TEXTURE & BUMP GENERATOR
       (Gleaming silver chip without black outlines, muted glare)
       ------------------------------------------------------------- */
    function buildCardAssets(config) {
        var cv = document.createElement('canvas');
        cv.width = 1024;
        cv.height = 1620;
        var ctx = cv.getContext('2d');

        var bumpCv = document.createElement('canvas');
        bumpCv.width = 1024;
        bumpCv.height = 1620;
        var bCtx = bumpCv.getContext('2d');
        bCtx.fillStyle = '#808080';
        bCtx.fillRect(0, 0, bumpCv.width, bumpCv.height);

        // Rich Satin Card Face Gradient
        var grad = ctx.createLinearGradient(0, 0, cv.width, cv.height);
        grad.addColorStop(0.0, config.top);
        grad.addColorStop(0.45, config.mid);
        grad.addColorStop(1.0, config.bot);
        ctx.fillStyle = grad;
        ctx.fillRect(0, 0, cv.width, cv.height);

        /* --- 1. Top Flower Mosaic Hub --- */
        ctx.save();
        var tint = config.patTint;
        var shade = config.patShade;

        // Big Upper Corner Disc
        ctx.fillStyle = tint;
        ctx.beginPath();
        ctx.arc(cv.width - 80, -30, 320, 0, Math.PI * 2);
        ctx.fill();

        function drawPetal(x1, y1, cp1x, cp1y, cp2x, cp2y, x2, y2, cp3x, cp3y, cp4x, cp4y, fill) {
            ctx.fillStyle = fill;
            ctx.beginPath();
            ctx.moveTo(x1, y1);
            ctx.bezierCurveTo(cp1x, cp1y, cp2x, cp2y, x2, y2);
            ctx.bezierCurveTo(cp3x, cp3y, cp4x, cp4y, x1, y1);
            ctx.closePath();
            ctx.fill();
        }

        // Radiating Flower Petals
        drawPetal(260, 180, 310, 70, 440, 20, 560, 90, 520, 230, 390, 280, tint);
        drawPetal(460, 50, 560, 20, 680, 80, 700, 200, 610, 230, 490, 160, shade);
        drawPetal(620, 130, 740, 100, 880, 160, 910, 290, 800, 330, 670, 260, tint);

        drawPetal(140, 340, 200, 230, 340, 200, 430, 280, 400, 410, 280, 450, shade);
        drawPetal(350, 290, 470, 235, 610, 290, 640, 420, 520, 460, 390, 400, tint);
        drawPetal(560, 360, 680, 320, 810, 380, 840, 510, 720, 550, 590, 490, shade);

        function drawRhombus(cx, cy, rw, rh, fill) {
            ctx.fillStyle = fill;
            ctx.beginPath();
            ctx.moveTo(cx, cy - rh);
            ctx.lineTo(cx + rw, cy);
            ctx.lineTo(cx, cy + rh);
            ctx.lineTo(cx - rw, cy);
            ctx.closePath();
            ctx.fill();
        }
        drawRhombus(470, 240, 34, 46, tint);
        drawRhombus(680, 330, 34, 46, shade);
        drawRhombus(270, 390, 34, 46, tint);

        // Lower Scalloped Shelf of the Flower at y = 570
        var domeY = 570;
        var r = 85;
        for (var d = 0; d < 7; d++) {
            ctx.fillStyle = (d % 2 === 0) ? shade : tint;
            ctx.beginPath();
            ctx.arc((d * r * 2) - 20, domeY, r, Math.PI, 0, false);
            ctx.fill();
        }

        ctx.strokeStyle = 'rgba(255, 255, 255, 0.22)';
        ctx.lineWidth = 4;
        ctx.beginPath();
        ctx.moveTo(0, domeY);
        ctx.lineTo(cv.width, domeY);
        ctx.stroke();
        ctx.restore();

        /* --- 2. Pennylane Ribbon Loop: Faint Matte Graphite Grey --- */
        ctx.save();
        ctx.strokeStyle = config.loopColor;
        ctx.lineWidth = 44;
        ctx.lineCap = 'round';
        ctx.lineJoin = 'round';
        ctx.beginPath();
        ctx.moveTo(340, 580);
        ctx.bezierCurveTo(210, 740, 160, 940, 290, 1070);
        ctx.bezierCurveTo(430, 1200, 670, 1060, 640, 860);
        ctx.bezierCurveTo(610, 690, 410, 710, 360, 890);
        ctx.bezierCurveTo(320, 1050, 350, 1220, 430, 1310);
        ctx.stroke();
        ctx.restore();

        /* --- 3. REDESIGNED CHIP: Clean, Gleaming Silver, No Black Outlines --- */
        var chipW = 142;
        var chipH = 178;
        var chipX = (cv.width - chipW) / 2;
        var chipY = 300;
        var chipRadius = 22;

        // Subtle recessed cavity drop-shadow (clean, no harsh lines)
        ctx.fillStyle = 'rgba(0, 0, 0, 0.4)';
        ctx.beginPath();
        ctx.roundRect ? ctx.roundRect(chipX - 3, chipY - 2, chipW + 6, chipH + 6, chipRadius + 2) : ctx.rect(chipX - 3, chipY - 2, chipW + 6, chipH + 6);
        ctx.fill();

        // Luminous Brushed Palladium/Silver Gradient Body
        var chipGrad = ctx.createLinearGradient(chipX, chipY, chipX + chipW, chipY + chipH);
        chipGrad.addColorStop(0.0, '#ffffff');
        chipGrad.addColorStop(0.2, '#f0f4f8');
        chipGrad.addColorStop(0.45, '#d4dbe2');
        chipGrad.addColorStop(0.75, '#b0bac4');
        chipGrad.addColorStop(1.0, '#e2e7ec');
        ctx.fillStyle = chipGrad;
        ctx.beginPath();
        ctx.roundRect ? ctx.roundRect(chipX, chipY, chipW, chipH, chipRadius) : ctx.rect(chipX, chipY, chipW, chipH);
        ctx.fill();

        // Delicate Top-Left Specular Rim (Bevel glint)
        ctx.strokeStyle = 'rgba(255, 255, 255, 0.85)';
        ctx.lineWidth = 1.2;
        ctx.stroke();

        // Hairline micro-brushing across silver surface
        ctx.fillStyle = 'rgba(255, 255, 255, 0.15)';
        for (var b = 4; b < chipH - 4; b += 3) {
            ctx.fillRect(chipX + 6, chipY + b, chipW - 12, 1);
        }

        // Minimalist Laser Engraved Micro-Grooves (Subtle, NO black borders)
        ctx.strokeStyle = 'rgba(45, 52, 60, 0.35)';
        ctx.lineWidth = 1.4;

        // Clean central vertical division line
        ctx.beginPath();
        ctx.moveTo(chipX + chipW / 2, chipY + 12);
        ctx.lineTo(chipX + chipW / 2, chipY + chipH - 12);
        ctx.stroke();

        // Two clean horizontal division lines
        ctx.beginPath();
        ctx.moveTo(chipX + 10, chipY + 58);
        ctx.lineTo(chipX + chipW - 10, chipY + 58);
        ctx.moveTo(chipX + 10, chipY + 120);
        ctx.lineTo(chipX + chipW - 10, chipY + 120);
        ctx.stroke();

        // Center Rounded Oval Island (Soft, elegant core)
        ctx.fillStyle = 'rgba(255, 255, 255, 0.28)';
        ctx.beginPath();
        ctx.roundRect ? ctx.roundRect(chipX + 44, chipY + 58, 54, 62, 14) : ctx.rect(chipX + 44, chipY + 58, 54, 62);
        ctx.fill();
        ctx.stroke();

        // Soft Bump Map for Chip (Silky surface relief, no deep trenches)
        bCtx.fillStyle = '#a8a8a8';
        bCtx.beginPath();
        bCtx.roundRect ? bCtx.roundRect(chipX, chipY, chipW, chipH, chipRadius) : bCtx.rect(chipX, chipY, chipW, chipH);
        bCtx.fill();
        bCtx.strokeStyle = '#606060';
        bCtx.lineWidth = 1.5;
        bCtx.stroke();

        /* --- 4. Brand Spine on Left Margin: ❖ Ethical --- */
        ctx.save();
        ctx.translate(125, 1460);
        ctx.rotate(-Math.PI / 2);

        ctx.fillStyle = '#ffffff';
        ctx.beginPath();
        ctx.moveTo(0, -22); ctx.lineTo(36, -7); ctx.lineTo(0, 8); ctx.closePath();
        ctx.fill();
        ctx.beginPath();
        ctx.moveTo(46, -16); ctx.lineTo(82, -1); ctx.lineTo(46, 14); ctx.closePath();
        ctx.fill();

        ctx.font = '800 76px system-ui, -apple-system, sans-serif';
        ctx.fillStyle = '#ffffff';
        ctx.shadowColor = 'rgba(0, 0, 0, 0.45)';
        ctx.shadowBlur = 8;
        ctx.shadowOffsetY = 3;
        ctx.fillText('Ethical', 110, 6);

        ctx.font = '700 24px system-ui, -apple-system, sans-serif';
        ctx.fillStyle = 'rgba(255, 255, 255, 0.8)';
        ctx.shadowBlur = 0;
        ctx.fillText(config.tier, 410, 0);
        ctx.restore();

        /* --- 5. Stretched 16-Digit Number (Bottom to Base of Flower) --- */
        var foilGrad = ctx.createLinearGradient(0, -30, 840, 0);
        if (config.tier === 'FOUNDATION') {
            foilGrad.addColorStop(0, '#fff6db');
            foilGrad.addColorStop(0.5, '#deb453');
            foilGrad.addColorStop(1, '#8f681a');
        } else {
            foilGrad.addColorStop(0, '#ffffff');
            foilGrad.addColorStop(0.35, '#dbe2ea');
            foilGrad.addColorStop(0.7, '#8fa0b2');
            foilGrad.addColorStop(1, '#ffffff');
        }

        var cardNum = config.number;
        ctx.save();
        ctx.translate(cv.width - 165, 1460);
        ctx.rotate(-Math.PI / 2);

        ctx.font = 'bold 50px "Courier New", Courier, monospace';
        ctx.letterSpacing = '7.5px';
        ctx.fillStyle = 'rgba(0, 0, 0, 0.75)';
        ctx.fillText(cardNum, 4, 4);
        ctx.fillStyle = 'rgba(15, 18, 22, 0.55)';
        ctx.fillText(cardNum, 2, 2);
        ctx.fillStyle = foilGrad;
        ctx.fillText(cardNum, 0, 0);
        ctx.fillStyle = 'rgba(255, 255, 255, 0.85)';
        ctx.fillText(cardNum, -1.2, -1.5);
        ctx.restore();

        bCtx.save();
        bCtx.translate(cv.width - 165, 1460);
        bCtx.rotate(-Math.PI / 2);
        bCtx.font = 'bold 50px "Courier New", Courier, monospace';
        bCtx.letterSpacing = '7.5px';
        bCtx.fillStyle = '#ffffff';
        bCtx.fillText(cardNum, 0, 0);
        bCtx.restore();

        // Line 2: Valid Thru & Cardholder Name (Roomy spacing)
        ctx.save();
        ctx.translate(cv.width - 85, 1460);
        ctx.rotate(-Math.PI / 2);

        ctx.font = '700 18px system-ui, -apple-system, sans-serif';
        ctx.fillStyle = 'rgba(255, 255, 255, 0.75)';
        ctx.fillText('VALID THRU', 0, -6);

        ctx.font = 'bold 36px "Courier New", Courier, monospace';
        ctx.fillStyle = 'rgba(0, 0, 0, 0.6)';
        ctx.fillText(config.valid, 136, -4);
        ctx.fillStyle = foilGrad;
        ctx.fillText(config.valid, 134, -6);

        ctx.font = 'bold 38px "Courier New", Courier, monospace';
        ctx.letterSpacing = '3px';
        ctx.fillStyle = 'rgba(0, 0, 0, 0.6)';
        ctx.fillText(config.holder, 320, -4);
        ctx.fillStyle = foilGrad;
        ctx.fillText(config.holder, 318, -6);
        ctx.restore();

        bCtx.save();
        bCtx.translate(cv.width - 85, 1460);
        bCtx.rotate(-Math.PI / 2);
        bCtx.font = 'bold 36px "Courier New", Courier, monospace';
        bCtx.fillStyle = '#ffffff';
        bCtx.fillText(config.valid, 134, -6);
        bCtx.font = 'bold 38px "Courier New", Courier, monospace';
        bCtx.letterSpacing = '3px';
        bCtx.fillStyle = '#ffffff';
        bCtx.fillText(config.holder, 318, -6);
        bCtx.restore();

        /* --- 6. VISA Logo: Oriented VERTICALLY & Unobstructed --- */
        ctx.save();
        ctx.translate((cv.width / 2) + 20, 1460);
        ctx.rotate(-Math.PI / 2);

        ctx.font = 'italic 900 80px system-ui, -apple-system, sans-serif';
        ctx.fillStyle = '#ffffff';
        ctx.shadowColor = 'rgba(0,0,0,0.45)';
        ctx.shadowBlur = 8;
        ctx.fillText('VISA', 0, 0);

        ctx.font = '800 20px system-ui, -apple-system, sans-serif';
        ctx.fillStyle = 'rgba(255,255,255,0.85)';
        ctx.shadowBlur = 0;
        ctx.letterSpacing = '3px';
        ctx.fillText('CLASSIC', 4, 28);
        ctx.restore();

        var texture = new THREE.CanvasTexture(cv);
        texture.anisotropy = renderer.capabilities.getMaxAnisotropy();

        var bumpMap = new THREE.CanvasTexture(bumpCv);
        bumpMap.anisotropy = 4;

        return { texture: texture, bumpMap: bumpMap };
    }

    /* -------------------------------------------------------------
       4. CONSTRUCT REAL 3D BEVELED SLABS WITH NORMALIZED UVs
       ------------------------------------------------------------- */
    var cardW = 3.6;
    var cardH = 5.7;
    var cardR = 0.32;

    var shape = new THREE.Shape();
    var sx = -cardW / 2, sy = -cardH / 2;
    shape.moveTo(sx + cardR, sy);
    shape.lineTo(sx + cardW - cardR, sy);
    shape.quadraticCurveTo(sx + cardW, sy, sx + cardW, sy + cardR);
    shape.lineTo(sx + cardW, sy + cardH - cardR);
    shape.quadraticCurveTo(sx + cardW, sy + cardH, sx + cardW - cardR, sy + cardH);
    shape.lineTo(sx + cardR, sy + cardH);
    shape.quadraticCurveTo(sx, sy + cardH, sx, sy + cardH - cardR);
    shape.lineTo(sx, sy + cardR);
    shape.quadraticCurveTo(sx, sy, sx + cardR, sy);

    var cardUVGenerator = {
        generateTopUV: function(geometry, vertices, indexA, indexB, indexC) {
            var ax = vertices[indexA * 3], ay = vertices[indexA * 3 + 1];
            var bx = vertices[indexB * 3], by = vertices[indexB * 3 + 1];
            var cx = vertices[indexC * 3], cy = vertices[indexC * 3 + 1];

            return [
                new THREE.Vector2((ax + cardW / 2) / cardW, (ay + cardH / 2) / cardH),
                new THREE.Vector2((bx + cardW / 2) / cardW, (by + cardH / 2) / cardH),
                new THREE.Vector2((cx + cardW / 2) / cardW, (cy + cardH / 2) / cardH)
            ];
        },
        generateSideWallUV: function(geometry, vertices, indexA, indexB, indexC, indexD) {
            return [
                new THREE.Vector2(0, 0),
                new THREE.Vector2(1, 0),
                new THREE.Vector2(1, 1),
                new THREE.Vector2(0, 1)
            ];
        }
    };

    var extrudeSettings = {
        depth: 0.12,
        bevelEnabled: true,
        bevelSegments: 4,
        steps: 1,
        bevelSize: 0.045,
        bevelThickness: 0.045,
        UVGenerator: cardUVGenerator
    };
    var cardGeometry = new THREE.ExtrudeGeometry(shape, extrudeSettings);
    cardGeometry.center();

    // 4 Saturated Color Tones
    var cardConfigs = [
        {
            tier: 'PREMIER',
            number: '9201 4417 9021 7342',
            valid: '06/31',
            holder: 'BARAKA MWANGI',
            top: '#181b1e', mid: '#101214', bot: '#08090a',
            patTint: '#525c66', patShade: '#1e242a',
            loopColor: 'rgba(255, 255, 255, 0.06)',
            rim: 0x22262a, rimGlow: 0x111315,
            pos: new THREE.Vector3(-0.6, -2.4, -1.8)
        },
        {
            tier: 'GROWTH',
            number: '9201 4417 7716 1094',
            valid: '11/29',
            holder: 'OTIENO BARASA',
            top: '#0a1d38', mid: '#061326', bot: '#030a14',
            patTint: '#3888ff', patShade: '#0a2e66',
            loopColor: 'rgba(56, 136, 255, 0.08)',
            rim: 0x0a1d38, rimGlow: 0x153d75,
            pos: new THREE.Vector3(0.4, -1.7, -0.6)
        },
        {
            tier: 'FOUNDATION',
            number: '9201 4417 2049 5530',
            valid: '03/30',
            holder: 'NJERI MWANGI',
            top: '#d4a23d', mid: '#9e6f1c', bot: '#5a3d08',
            patTint: '#f5d576', patShade: '#785210',
            loopColor: 'rgba(245, 213, 118, 0.08)',
            rim: 0xd4a23d, rimGlow: 0x9e6f1c,
            pos: new THREE.Vector3(1.4, -1.0, 0.6)
        },
        {
            // HERO CARD: JET BLACK WITH OPTION A GOLD FLOWERS & FAINT MATTE GREY LOOP
            tier: 'ELITE BLACK',
            number: '9201 4417 5588 6603',
            valid: '01/32',
            holder: 'WANJIRU KAMAU',
            top: '#0d0f11', mid: '#08090a', bot: '#030405',
            patTint: '#fad674', patShade: '#8e6518',
            loopColor: 'rgba(255, 255, 255, 0.085)',
            rim: 0x08090a, rimGlow: 0x000000,
            pos: new THREE.Vector3(2.4, -0.3, 1.8)
        }
    ];

    var rigGroup = new THREE.Group();
    scene.add(rigGroup);

    var cardMeshes = [];

    var targetRotX = -0.32;
    var targetRotY = -0.28;
    var targetRotZ = -0.34;

    cardConfigs.forEach(function (cfg, idx) {
        var assets = buildCardAssets(cfg);

        // Calibrated Satin Surface (Dimmed glare, velvet sheen)
        var faceMaterial = new THREE.MeshPhysicalMaterial({
            map: assets.texture,
            bumpMap: assets.bumpMap,
            bumpScale: 0.045,
            roughness: 0.46,          // Velvety satin, removes the harsh specular hotspot
            metalness: 0.04,
            clearcoat: 0.14,          // Controlled, soft glint
            clearcoatRoughness: 0.35
        });

        var rimMaterial = new THREE.MeshPhysicalMaterial({
            color: cfg.rim,
            emissive: cfg.rimGlow,
            emissiveIntensity: 0.25,
            roughness: 0.18,
            metalness: 0.1,
            clearcoat: 1.0,
            clearcoatRoughness: 0.08
        });

        var mesh = new THREE.Mesh(cardGeometry, [faceMaterial, rimMaterial]);
        mesh.castShadow = true;
        mesh.receiveShadow = true;

        mesh.position.set(cfg.pos.x * 1.4, cfg.pos.y - 4.5, cfg.pos.z - 2.0);
        mesh.rotation.set(0.6, 0, 0);

        mesh.userData = {
            basePos: cfg.pos.clone(),
            finalRot: new THREE.Vector3(targetRotX, targetRotY, targetRotZ),
            delay: idx * 0.14,
            index: idx
        };

        rigGroup.add(mesh);
        cardMeshes.push(mesh);
    });

    /* -------------------------------------------------------------
       5. SLOWER MAJESTIC ANIMATION, FANNING & RENDER LOOP
       ------------------------------------------------------------- */
    var clock = new THREE.Clock();
    var introProgress = 0;
    var targetRigRotX = 0;
    var targetRigRotY = 0;
    var isHovered = false;

    function resize() {
        var w = container.clientWidth;
        var h = container.clientHeight;
        if (w === 0 || h === 0) return;

        camera.aspect = w / h;
        camera.updateProjectionMatrix();
        renderer.setSize(w, h);
    }
    window.addEventListener('resize', resize);
    resize();

    if (!reduce && window.matchMedia('(pointer: fine)').matches) {
        window.addEventListener('mousemove', function (e) {
            if (glow) {
                glow.style.opacity = '1';
                glow.style.left = e.clientX + 'px';
                glow.style.top = e.clientY + 'px';
            }

            var rect = container.getBoundingClientRect();
            var dx = (e.clientX - (rect.left + rect.width / 2)) / window.innerWidth;
            var dy = (e.clientY - (rect.top + rect.height / 2)) / window.innerHeight;

            targetRigRotX = dy * -0.15;
            targetRigRotY = dx * 0.18;

            shineSweepLight.position.x = 1.0 + dx * 3.5;
            shineSweepLight.position.y = 1.5 - dy * 3.5;

            isHovered = (e.clientX > window.innerWidth * 0.45);
        }, { passive: true });

        document.addEventListener('mouseleave', function () {
            if (glow) glow.style.opacity = '0';
            targetRigRotX = 0;
            targetRigRotY = 0;
            isHovered = false;
        });
    }

    function animate() {
        requestAnimationFrame(animate);
        var delta = clock.getDelta();
        var elapsed = clock.getElapsedTime();

        // 1. Stately Intro Rise (Smooth cubic ease-out)
        if (introProgress < 1.0) {
            introProgress += delta * 0.28;
            cardMeshes.forEach(function (m) {
                var p = Math.max(0, Math.min(1, (introProgress - m.userData.delay) / 0.65));
                var ease = 1 - Math.pow(1 - p, 3);

                m.position.lerpVectors(
                    new THREE.Vector3(m.userData.basePos.x * 1.4, m.userData.basePos.y - 4.5, m.userData.basePos.z - 2.0),
                    m.userData.basePos,
                    ease
                );
                m.rotation.x = THREE.MathUtils.lerp(0.6, m.userData.finalRot.x, ease);
                m.rotation.y = THREE.MathUtils.lerp(0, m.userData.finalRot.y, ease);
                m.rotation.z = THREE.MathUtils.lerp(0, m.userData.finalRot.z, ease);
            });
        } else {
            // 2. Interactive Card Fanning & Subtle Breathing Loop
            cardMeshes.forEach(function (m, i) {
                var wave = Math.sin(elapsed * 1.2 + i * 0.6) * 0.035;

                var fanOffsetX = 0;
                var fanOffsetZ = 0;
                if (isHovered) {
                    fanOffsetX = (3 - i) * -0.22;
                    fanOffsetZ = (i === 3) ? 0.35 : (i * 0.08);
                }

                var targetX = m.userData.basePos.x + fanOffsetX;
                var targetY = m.userData.basePos.y + wave;
                var targetZ = m.userData.basePos.z + fanOffsetZ;

                m.position.x += (targetX - m.position.x) * 0.08;
                m.position.y += (targetY - m.position.y) * 0.08;
                m.position.z += (targetZ - m.position.z) * 0.08;
            });

            if (!isHovered) {
                shineSweepLight.position.x = 1.2 + Math.sin(elapsed * 0.8) * 2.0;
                shineSweepLight.position.y = 1.0 + Math.cos(elapsed * 0.8) * 1.2;
            }
        }

        // 3. Smooth Camera Rig Parallax
        rigGroup.rotation.x += (targetRigRotX - rigGroup.rotation.x) * 0.08;
        rigGroup.rotation.y += (targetRigRotY - rigGroup.rotation.y) * 0.08;

        renderer.render(scene, camera);
    }
    animate();

})();