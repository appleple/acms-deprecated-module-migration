const path = require('path');

module.exports = {
  entry: {
    admin: './assets/src/index.ts',
  },
  output: {
    path: path.join(__dirname, 'src/dist'),
    filename: '[name].js',
    chunkFilename: '[name].chunk.js',
  },
  resolve: {
    extensions: ['.js', '.ts', '.tsx'],
  },
  module: {
    rules: [
      {
        test: /\.tsx?$/,
        use: 'ts-loader',
        exclude: /node_modules/,
      },
    ],
  },
};
