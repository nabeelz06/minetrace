const Ziggy = {"url":"http:\/\/localhost","port":null,"defaults":{},"routes":{"telescope":{"uri":"telescope\/{view?}","methods":["GET","HEAD"],"wheres":{"view":"(.*)"},"parameters":["view"]},"stancl.tenancy.asset":{"uri":"tenancy\/assets\/{path?}","methods":["GET","HEAD"],"wheres":{"path":"(.*)"},"parameters":["path"]},"login":{"uri":"login","methods":["GET","HEAD"]},"logout":{"uri":"logout","methods":["POST"]},"dashboard.redirect":{"uri":"dashboard","methods":["GET","HEAD"]},"super-admin.dashboard":{"uri":"super-admin\/dashboard","methods":["GET","HEAD"]},"bim.dashboard":{"uri":"bim\/dashboard","methods":["GET","HEAD"]},"company.dashboard":{"uri":"company\/dashboard","methods":["GET","HEAD"]},"storage.local":{"uri":"storage\/{path}","methods":["GET","HEAD"],"wheres":{"path":".*"},"parameters":["path"]}}};
if (typeof window !== 'undefined' && typeof window.Ziggy !== 'undefined') {
  Object.assign(Ziggy.routes, window.Ziggy.routes);
}
export { Ziggy };
