precision mediump float;
/** @resolution */
uniform vec2 u_resolution;
/** @content */
uniform sampler2D u_content;
void main() {
  vec4 source = texture2D(u_content, gl_FragCoord.xy / u_resolution);
  vec3 rgb = source.rgb / max(source.a, 0.0001);
  float ink = 1.0 - smoothstep(0.58, 0.94, min(rgb.r, min(rgb.g, rgb.b)));
  float alpha = source.a * ink;
  float cyan = step(0.27, rgb.b - rgb.r) * step(0.37, rgb.g);
  vec3 color = mix(vec3(1.0), rgb, cyan);
  gl_FragColor = vec4(color * alpha, alpha);
}
