// Fullscreen-quad vertex shader — just passes clip-space positions.
export const VERT = `
attribute vec2 a_pos;
void main() {
    gl_Position = vec4(a_pos, 0.0, 1.0);
}
`;

// Fragment shader: a *simulated* frequency field. There is no real audio in the browser
// (the Pi broadcasts over FM), so the "spectrum" is synthesised from time + a per-song seed
// + a simulated BPM beat, then art-directed with the commute palette. Cheap: one pass, no
// textures, small loops.
export const FRAG = `
precision highp float;

uniform vec2  u_res;
uniform float u_time;
uniform float u_intensity;  // 0..1 overall energy (idle -> playing)
uniform float u_seed;       // per-song, reshapes the spectrum
uniform float u_progress;   // 0..1 elapsed through the track
uniform float u_bps;        // simulated beats per second
uniform vec3  u_low;
uniform vec3  u_mid;
uniform vec3  u_high;

float hash(float n) { return fract(sin(n) * 43758.5453123); }

float noise(vec2 p) {
    vec2 i = floor(p);
    vec2 f = fract(p);
    f = f * f * (3.0 - 2.0 * f);
    float a = hash(i.x + i.y * 57.0);
    float b = hash(i.x + 1.0 + i.y * 57.0);
    float c = hash(i.x + (i.y + 1.0) * 57.0);
    float d = hash(i.x + 1.0 + (i.y + 1.0) * 57.0);
    return mix(mix(a, b, f.x), mix(c, d, f.x), f.y);
}

// Simulated spectral magnitude at horizontal position x (0..1).
float spectrum(float x) {
    float s = 0.0;
    for (int i = 1; i <= 6; i++) {
        float fi = float(i);
        float freq = fi * 3.0 + u_seed * 2.0;
        float phase = u_time * (0.6 + 0.15 * fi) + u_seed * fi;
        s += (1.0 / fi) * (0.5 + 0.5 * sin(6.2831 * freq * x + phase));
    }
    return s * 0.5;
}

void main() {
    vec2 uv = gl_FragCoord.xy / u_res;

    // Simulated kick: sharp pulse u_bps times per second.
    float beat = pow(0.5 + 0.5 * sin(u_time * 6.2831 * u_bps), 6.0);
    float energy = u_intensity * (0.6 + 0.4 * beat);

    // Spectrum band rising from the bottom.
    float mag = spectrum(uv.x) * energy;
    mag *= 0.6 + 0.4 * noise(vec2(uv.x * 4.0, u_time * 0.3));
    float barTop = 0.02 + mag * 0.5;
    float band = smoothstep(barTop, barTop - 0.16, uv.y);
    float line = smoothstep(0.02, 0.0, abs(uv.y - barTop)) * energy;

    // Slow plasma drift for depth.
    float p = noise(uv * 3.0 + vec2(u_time * 0.15, u_time * 0.10));
    p += 0.5 * noise(uv * 6.0 - vec2(u_time * 0.10, 0.0));

    // Vertical colour ramp low -> mid -> high.
    float t = clamp(uv.y * 1.2 + mag * 0.6, 0.0, 1.0);
    vec3 col = mix(u_low, u_mid, smoothstep(0.0, 0.6, t));
    col = mix(col, u_high, smoothstep(0.55, 1.0, t));

    vec3 outc = u_low * 0.22;
    outc += col * band * 0.9;
    outc += u_high * line * 1.4;
    outc += col * p * 0.06 * energy;

    // Faint progress sweep — a soft vertical line at the playhead.
    float sweep = smoothstep(0.004, 0.0, abs(uv.x - u_progress)) * 0.28 * energy;
    outc += u_high * sweep;

    // Vignette to keep edges calm behind content.
    outc *= smoothstep(1.25, 0.3, length(uv - 0.5));

    gl_FragColor = vec4(outc, 1.0);
}
`;
