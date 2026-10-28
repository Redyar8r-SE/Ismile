precision mediump float;
/** @resolution */
uniform vec2 u_resolution;
/** @content */
uniform sampler2D u_content;
void main() {
  vec2 uv = gl_FragCoord.xy / u_resolution;
  vec4 source = texture2D(u_content, uv);
  vec3 rgb = source.rgb / max(source.a, 0.0001);
  float alpha = source.a;
  vec3 color = mix(rgb, vec3(1.0), 1.0 - step(0.32, uv.y));
  gl_FragColor = vec4(color * alpha, alpha);
}
