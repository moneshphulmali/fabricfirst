const express = require("express");
const bodyParser = require("body-parser");
const fs = require("fs");
const { exec } = require("child_process");

const app = express();
app.use(bodyParser.text({ type: "*/*" }));

app.post("/print", (req, res) => {
    const tspl = req.body;

    if (!tspl || tspl.trim() === "") {
        return res.status(400).send("❌ Error: TSPL data is missing");
    }

    const file = "tsc_job.txt";
    fs.writeFileSync(file, tspl);

    const command = `powershell -command Start-Process -FilePath "${file}" -Verb Print`;

    exec(command, (err, stdout, stderr) => {
        if (err) {
            console.log(err);
            return res.status(500).send("❌ Printing Error: " + err.message);
        }
        res.send("✅ Print sent successfully!");
    });
});

app.listen(3000, () => {
    console.log("🚀 TSC Print Server Running on port 3000");
});
